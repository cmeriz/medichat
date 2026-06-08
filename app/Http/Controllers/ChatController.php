<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Models\AiRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Patient;
use App\Models\UrineExam;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    private const GPT_4O_MINI_INPUT_COST_PER_MILLION = 0.15;
    private const GPT_4O_MINI_CACHED_INPUT_COST_PER_MILLION = 0.075;
    private const GPT_4O_MINI_OUTPUT_COST_PER_MILLION = 0.60;

    public function session(Request $request): JsonResponse
    {
        $request->session()->forget([
            'patient_id',
            'conversation_id',
            'pending_identification_number',
        ]);

        $request->session()->put('chat_started', true);

        return response()->json([
            'session_id' => $request->session()->getId(),
            'message' => $this->assistantMessage($this->currentPrompt($request)),
        ]);
    }

    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:2000', 'required_without:exam_file'],
            'exam_file' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        $content = trim($validated['content'] ?? '');
        $reply = $this->handleIncomingMessage($request, $content, $request->file('exam_file'));

        broadcast(new ChatMessageSent(
            $request->session()->getId(),
            $this->assistantMessage($reply),
        ));

        return response()->json([
            'ok' => true,
        ]);
    }

    private function handleIncomingMessage(Request $request, string $content, ?UploadedFile $examFile): string
    {
        if ($request->session()->get('patient_id')) {
            return $this->answerWithAi($request, $content, $examFile);
        }

        if ($examFile) {
            return 'Please enter your identification number before attaching an exam PDF.';
        }

        if ($request->session()->has('pending_identification_number')) {
            $patient = Patient::create([
                'identification_number' => $request->session()->pull('pending_identification_number'),
                'name' => Str::of($content)->squish()->limit(255, '')->toString(),
            ]);

            $conversation = Conversation::create([
                'patient_id' => $patient->id,
            ]);

            $request->session()->put('patient_id', $patient->id);
            $request->session()->put('conversation_id', $conversation->id);

            return "Nice to meet you, {$this->patientName($patient)}. What can I do for you today?";
        }

        $identificationNumber = Str::of($content)->squish()->toString();
        $patient = Patient::where('identification_number', $identificationNumber)->first();

        if (! $patient) {
            $request->session()->put('pending_identification_number', $identificationNumber);

            return "I don't recognize that identification. What is your name?";
        }

        $conversation = Conversation::create([
            'patient_id' => $patient->id,
        ]);

        $request->session()->put('patient_id', $patient->id);
        $request->session()->put('conversation_id', $conversation->id);

        return "Welcome back, {$this->patientName($patient)}. What can I do for you today?";
    }

    private function answerWithAi(Request $request, string $content, ?UploadedFile $examFile): string
    {
        $conversation = Conversation::query()
            ->whereKey($request->session()->get('conversation_id'))
            ->firstOrFail();

        $currentUserMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $this->userMessageContent($content, $examFile),
        ]);

        try {
            if ($examFile) {
                $reply = $this->analyzeExamPdf($conversation, $currentUserMessage, $content, $examFile);
            } else {
                $reply = $this->createChatCompletion($conversation, $currentUserMessage);
            }
        } catch (Exception $exception) {
            report($exception);

            $reply = 'I could not reach the AI service right now. Please try again in a moment.';
        }

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
        ]);

        try {
            $this->summarizeOlderMessages($conversation->fresh());
        } catch (Exception $exception) {
            report($exception);
        }

        return $reply;
    }

    private function analyzeExamPdf(
        Conversation $conversation,
        Message $currentUserMessage,
        string $content,
        UploadedFile $examFile,
    ): string {
        $model = config('services.openai.model', 'gpt-4o-mini');
        $messages = $this->messagesForExamExtraction($conversation, $content, $examFile);

        try {
            $response = $this->openAiClient()->chat()->create([
                'model' => $model,
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => $messages,
            ]);
        } catch (Exception $exception) {
            $this->recordAiRequest($conversation, 'exam_pdf', $model, $messages, null, $exception);

            throw $exception;
        }

        $this->recordAiRequest($conversation, 'exam_pdf', $model, $messages, $response);

        $payload = json_decode($response->choices[0]->message->content ?? '{}', true);

        if (! is_array($payload)) {
            return 'I could not read that PDF clearly. Please try another PDF version of the exam.';
        }

        if (! in_array($payload['document_type'] ?? null, ['blood_exam', 'urine_exam'], true)) {
            return $payload['user_response'] ?? 'This document is not supported yet. For now I can only analyze blood exam and urine exam PDFs.';
        }

        if (blank($payload['exam_date'] ?? null)) {
            return 'I can analyze this exam, but I could not find the exam date. Please send the PDF again and include the exam date in your message.';
        }

        if (! $this->isValidExamDate($payload['exam_date'])) {
            return 'I found a possible exam date, but it was not clear enough to save. Please send the PDF again and include the exam date in YYYY-MM-DD format.';
        }

        $exam = $this->storeExamFromExtraction($conversation, $payload);
        $currentUserMessage->update([
            'examable_type' => $exam::class,
            'examable_id' => $exam->id,
        ]);

        return $payload['user_response'] ?? 'I extracted and saved this exam. Please consult a qualified clinician before making medical decisions from these results.';
    }

    private function createChatCompletion(Conversation $conversation, Message $currentUserMessage): string
    {
        $model = config('services.openai.model', 'gpt-4o-mini');
        $messages = $this->messagesForAi($conversation, $currentUserMessage);

        try {
            $response = $this->openAiClient()->chat()->create([
                'model' => $model,
                'temperature' => 0.2,
                'messages' => $messages,
            ]);
        } catch (Exception $exception) {
            $this->recordAiRequest($conversation, 'chat', $model, $messages, null, $exception);

            throw $exception;
        }

        $this->recordAiRequest($conversation, 'chat', $model, $messages, $response);

        return trim($response->choices[0]->message->content ?? 'I need a little more detail to help with that health question.');
    }

    private function createSummaryCompletion(Conversation $conversation, array $messages): string
    {
        $model = config('services.openai.model', 'gpt-4o-mini');

        try {
            $response = $this->openAiClient()->chat()->create([
                'model' => $model,
                'temperature' => 0.1,
                'messages' => $messages,
            ]);
        } catch (Exception $exception) {
            $this->recordAiRequest($conversation, 'summary', $model, $messages, null, $exception);

            throw $exception;
        }

        $this->recordAiRequest($conversation, 'summary', $model, $messages, $response);

        return trim($response->choices[0]->message->content ?? $conversation->context_summary ?? '');
    }

    private function messagesForExamExtraction(Conversation $conversation, string $content, UploadedFile $examFile): array
    {
        $conversation->load('patient');
        $userText = $content ?: 'Please analyze this exam PDF.';

        return [
            [
                'role' => 'system',
                'content' => $this->examExtractionPrompt($conversation),
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $userText,
                    ],
                    [
                        'type' => 'file',
                        'file' => [
                            'filename' => $examFile->getClientOriginalName(),
                            'file_data' => 'data:application/pdf;base64,'.base64_encode(file_get_contents($examFile->getRealPath())),
                        ],
                    ],
                ],
            ],
        ];
    }

    private function examExtractionPrompt(Conversation $conversation): string
    {
        $patientName = $this->patientName($conversation->patient);

        return <<<PROMPT
You are MediChat, a medical exam extraction assistant.

Patient name: {$patientName}

Analyze the attached PDF. For this demo, assume the exam belongs to the current patient even if the PDF names another person.

Supported document types:
- blood_exam
- urine_exam

If the PDF is not a blood exam or urine exam, return document_type "unsupported" and do not extract values.

Rules:
- Return only valid JSON. No Markdown outside JSON.
- exam_date is required. Use YYYY-MM-DD.
- If no exam date appears in the PDF or user message, set exam_date to null.
- Use null for any value that is not present.
- Normalize blood count units for storage: rbc as millions/uL, wbc and absolute differentials as thousands/uL, and platelets as thousands/uL.
- Example: RBC 5,290,000 becomes 5.29, WBC 7,210 becomes 7.21, platelets 274,000 becomes 274.
- Put any raw extraction notes or unrecognized values in ai_extraction_raw.
- user_response must be Markdown for the patient.
- Do not diagnose. Explain notable findings cautiously and recommend clinician review.

JSON shape:
{
  "document_type": "blood_exam|urine_exam|unsupported",
  "exam_date": "YYYY-MM-DD|null",
  "lab_name": "string|null",
  "blood_exam": {
    "rbc": null, "hemoglobin": null, "hematocrit": null, "mcv": null, "mch": null, "mchc": null, "rdw": null,
    "wbc": null, "neutrophils_pct": null, "neutrophils_abs": null, "lymphocytes_pct": null, "lymphocytes_abs": null,
    "monocytes_pct": null, "monocytes_abs": null, "eosinophils_pct": null, "eosinophils_abs": null,
    "basophils_pct": null, "basophils_abs": null, "platelets": null, "mpv": null,
    "glucose": null, "bun": null, "creatinine": null, "egfr": null, "sodium": null, "potassium": null,
    "chloride": null, "co2": null, "calcium": null, "total_cholesterol": null, "hdl_cholesterol": null,
    "ldl_cholesterol": null, "vldl_cholesterol": null, "triglycerides": null, "total_bilirubin": null,
    "direct_bilirubin": null, "indirect_bilirubin": null, "ast": null, "alt": null, "alp": null, "ggt": null,
    "total_protein": null, "albumin": null, "tsh": null, "free_t4": null, "free_t3": null,
    "serum_iron": null, "tibc": null, "transferrin_saturation": null, "ferritin": null,
    "crp": null, "esr": null, "hba1c": null, "fasting_insulin": null
  },
  "urine_exam": {
    "color": null, "appearance": null, "specific_gravity": null, "ph": null, "protein": null, "glucose": null,
    "ketones": null, "bilirubin": null, "urobilinogen": null, "blood": null, "nitrite": null,
    "leukocyte_esterase": null, "wbc": null, "rbc": null, "epithelial_cells": null, "bacteria": null,
    "casts": null, "crystals": null, "mucus": null, "yeast": null
  },
  "ai_extraction_raw": {},
  "notes": "string|null",
  "user_response": "Markdown response"
}
PROMPT;
    }

    private function storeExamFromExtraction(Conversation $conversation, array $payload)
    {
        $common = [
            'patient_id' => $conversation->patient_id,
            'exam_date' => $payload['exam_date'],
            'lab_name' => $payload['lab_name'] ?? null,
            'file_path' => null,
            'file_original_name' => null,
            'ai_extraction_raw' => $payload['ai_extraction_raw'] ?? $payload,
            'notes' => $payload['notes'] ?? null,
        ];

        if (($payload['document_type'] ?? null) === 'urine_exam') {
            $urineValues = $this->onlyAllowed($payload['urine_exam'] ?? [], [
                'color',
                'appearance',
                'protein',
                'glucose',
                'ketones',
                'bilirubin',
                'urobilinogen',
                'blood',
                'nitrite',
                'leukocyte_esterase',
                'wbc',
                'rbc',
                'epithelial_cells',
                'bacteria',
                'casts',
                'crystals',
                'mucus',
                'yeast',
            ]);

            $urineValues['specific_gravity'] = $this->decimalOrNull(data_get($payload, 'urine_exam.specific_gravity'));
            $urineValues['ph'] = $this->decimalOrNull(data_get($payload, 'urine_exam.ph'));

            return UrineExam::create(array_merge($common, $urineValues));
        }

        return \App\Models\BloodExam::create(array_merge($common, $this->bloodExamValues($payload['blood_exam'] ?? [], [
            'rbc',
            'hemoglobin',
            'hematocrit',
            'mcv',
            'mch',
            'mchc',
            'rdw',
            'wbc',
            'neutrophils_pct',
            'neutrophils_abs',
            'lymphocytes_pct',
            'lymphocytes_abs',
            'monocytes_pct',
            'monocytes_abs',
            'eosinophils_pct',
            'eosinophils_abs',
            'basophils_pct',
            'basophils_abs',
            'platelets',
            'mpv',
            'glucose',
            'bun',
            'creatinine',
            'egfr',
            'sodium',
            'potassium',
            'chloride',
            'co2',
            'calcium',
            'total_cholesterol',
            'hdl_cholesterol',
            'ldl_cholesterol',
            'vldl_cholesterol',
            'triglycerides',
            'total_bilirubin',
            'direct_bilirubin',
            'indirect_bilirubin',
            'ast',
            'alt',
            'alp',
            'ggt',
            'total_protein',
            'albumin',
            'tsh',
            'free_t4',
            'free_t3',
            'serum_iron',
            'tibc',
            'transferrin_saturation',
            'ferritin',
            'crp',
            'esr',
            'hba1c',
            'fasting_insulin',
        ])));
    }

    private function onlyAllowed(array $values, array $allowed): array
    {
        return collect($values)
            ->only($allowed)
            ->map(fn ($value) => $value === '' ? null : $value)
            ->all();
    }

    private function decimalValues(array $values, array $allowed): array
    {
        return collect($allowed)
            ->mapWithKeys(fn (string $key): array => [$key => $this->decimalOrNull($values[$key] ?? null)])
            ->all();
    }

    private function bloodExamValues(array $values, array $allowed): array
    {
        $normalized = $this->decimalValues($values, $allowed);

        if (($normalized['rbc'] ?? null) > 1000) {
            $normalized['rbc'] = round($normalized['rbc'] / 1_000_000, 2);
        }

        foreach (['wbc', 'neutrophils_abs', 'lymphocytes_abs', 'monocytes_abs', 'eosinophils_abs', 'basophils_abs'] as $key) {
            if (($normalized[$key] ?? null) > 100) {
                $normalized[$key] = round($normalized[$key] / 1000, 2);
            }
        }

        if (($normalized['platelets'] ?? null) > 1000) {
            $normalized['platelets'] = round($normalized['platelets'] / 1000, 2);
        }

        return $normalized;
    }

    private function decimalOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/-?\d+(?:\.\d+)?/', $value, $matches)) {
            return (float) $matches[0];
        }

        return null;
    }

    private function isValidExamDate(string $date): bool
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $date)->format('Y-m-d') === $date;
        } catch (Exception) {
            return false;
        }
    }

    private function userMessageContent(string $content, ?UploadedFile $examFile): string
    {
        if (! $examFile) {
            return $content;
        }

        $attachmentLine = '[Attached PDF: '.$examFile->getClientOriginalName().']';

        return trim($content) !== '' ? trim($content)."\n\n".$attachmentLine : $attachmentLine;
    }

    private function recordAiRequest(
        Conversation $conversation,
        string $requestType,
        string $model,
        array $messages,
        mixed $response = null,
        ?Exception $exception = null,
    ): void {
        $inputTokens = (int) (data_get($response, 'usage.promptTokens') ?? data_get($response, 'usage.prompt_tokens') ?? 0);
        $cachedInputTokens = (int) (data_get($response, 'usage.promptTokensDetails.cachedTokens') ?? data_get($response, 'usage.prompt_tokens_details.cached_tokens') ?? 0);
        $outputTokens = (int) (data_get($response, 'usage.completionTokens') ?? data_get($response, 'usage.completion_tokens') ?? 0);
        $totalTokens = (int) (data_get($response, 'usage.totalTokens') ?? data_get($response, 'usage.total_tokens') ?? ($inputTokens + $outputTokens));
        $billableInputTokens = max($inputTokens - $cachedInputTokens, 0);

        AiRequest::create([
            'patient_id' => $conversation->patient_id,
            'conversation_id' => $conversation->id,
            'model' => $model,
            'endpoint' => 'chat.completions',
            'request_type' => $requestType,
            'prompt_messages' => $this->messagesForAudit($messages),
            'response_text' => data_get($response, 'choices.0.message.content'),
            'response_id' => data_get($response, 'id'),
            'finish_reason' => data_get($response, 'choices.0.finishReason') ?? data_get($response, 'choices.0.finish_reason'),
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'input_cost_per_million' => self::GPT_4O_MINI_INPUT_COST_PER_MILLION,
            'cached_input_cost_per_million' => self::GPT_4O_MINI_CACHED_INPUT_COST_PER_MILLION,
            'output_cost_per_million' => self::GPT_4O_MINI_OUTPUT_COST_PER_MILLION,
            'estimated_cost_usd' => $this->estimateGpt4oMiniCost($billableInputTokens, $cachedInputTokens, $outputTokens),
            'error' => $exception?->getMessage(),
        ]);
    }

    private function messagesForAudit(array $messages): array
    {
        return collect($messages)
            ->map(fn (array $message): array => $this->sanitizeMessageForAudit($message))
            ->all();
    }

    private function sanitizeMessageForAudit(array $message): array
    {
        if (! is_array($message['content'] ?? null)) {
            return $message;
        }

        $message['content'] = collect($message['content'])
            ->map(function (array $part): array {
                if (($part['type'] ?? null) === 'file') {
                    $part['file'] = [
                        'filename' => $part['file']['filename'] ?? null,
                        'file_data' => '[PDF omitted from audit log]',
                    ];
                }

                return $part;
            })
            ->all();

        return $message;
    }

    private function estimateGpt4oMiniCost(int $inputTokens, int $cachedInputTokens, int $outputTokens): float
    {
        return round(
            ($inputTokens / 1_000_000 * self::GPT_4O_MINI_INPUT_COST_PER_MILLION)
            + ($cachedInputTokens / 1_000_000 * self::GPT_4O_MINI_CACHED_INPUT_COST_PER_MILLION)
            + ($outputTokens / 1_000_000 * self::GPT_4O_MINI_OUTPUT_COST_PER_MILLION),
            8,
        );
    }

    private function messagesForAi(Conversation $conversation, Message $currentUserMessage): array
    {
        $conversation->load('patient');

        $messages = [
            [
                'role' => 'system',
                'content' => $this->systemPrompt($conversation),
            ],
        ];

        foreach ($this->recentInteractionMessagesForAi($conversation, $currentUserMessage) as $message) {
            $messages[] = [
                'role' => $message->role,
                'content' => $message->content,
            ];
        }

        return $messages;
    }

    private function systemPrompt(Conversation $conversation): string
    {
        $summary = $conversation->context_summary ?: 'No older conversation summary yet.';
        $patientName = $this->patientName($conversation->patient);

        return <<<PROMPT
You are MediChat, a demo assistant that only discusses health, personal health questions, medical conditions, symptoms, medical exams, and blood exam results.

Patient name: {$patientName}

Older summarized conversation:
{$summary}

Format:
- Respond in Markdown.
- Use short paragraphs.
- Use bullet lists or numbered lists when they make the answer easier to read.
- Use bold text only for important labels or warnings.

Rules:
- If the user asks about anything unrelated to health or their health, politely refuse and say you can only help with health, medical conditions, symptoms, or medical exam results.
- Do not invent exam values or claim a diagnosis.
- Explain in clear, simple language.
- Encourage the user to consult a qualified clinician for medical decisions.
- For urgent or severe symptoms, tell the user to seek urgent medical care.
PROMPT;
    }

    private function recentRawMessages(Conversation $conversation)
    {
        return $conversation->messages()
            ->latest('id')
            ->limit(10)
            ->get()
            ->sortBy('id')
            ->values();
    }

    private function recentInteractionMessagesForAi(Conversation $conversation, Message $currentUserMessage)
    {
        $previousMessages = $conversation->messages()
            ->where('id', '<', $currentUserMessage->id)
            ->latest('id')
            ->limit(30)
            ->get();

        $pairs = [];
        $pendingAssistant = null;

        foreach ($previousMessages as $message) {
            if ($message->role === 'assistant') {
                $pendingAssistant = $message;

                continue;
            }

            if ($message->role === 'user' && $pendingAssistant) {
                $pairs[] = [$message, $pendingAssistant];
                $pendingAssistant = null;
            }

            if (count($pairs) === 5) {
                break;
            }
        }

        return collect($pairs)
            ->reverse()
            ->flatMap(fn (array $pair): array => $pair)
            ->push($currentUserMessage)
            ->values();
    }

    private function summarizeOlderMessages(Conversation $conversation): void
    {
        $recentMessageIds = $this->recentRawMessages($conversation)->pluck('id');

        $messagesToSummarize = $conversation->messages()
            ->whereNotIn('id', $recentMessageIds)
            ->when($conversation->summary_from_message_id, function ($query, int $messageId): void {
                $query->where('id', '>', $messageId);
            })
            ->orderBy('id')
            ->get();

        if ($messagesToSummarize->isEmpty()) {
            return;
        }

        $transcript = $messagesToSummarize
            ->map(fn (Message $message): string => strtoupper($message->role).': '.$message->content)
            ->implode("\n");

        $messages = [
            [
                'role' => 'system',
                'content' => 'Summarize the medical chat context compactly. Preserve health concerns, symptoms, exam references, preferences, and unresolved follow-ups. Do not add information that is not present.',
            ],
            [
                'role' => 'user',
                'content' => "Existing summary:\n".($conversation->context_summary ?: 'None')."\n\nNew transcript to fold into the summary:\n{$transcript}",
            ],
        ];

        $summary = $this->createSummaryCompletion($conversation, $messages);

        $conversation->update([
            'context_summary' => $summary,
            'context_updated_at' => now(),
            'summary_from_message_id' => $messagesToSummarize->max('id'),
        ]);

        Message::whereIn('id', $messagesToSummarize->pluck('id'))->update([
            'included_in_summary' => true,
        ]);
    }

    private function openAiClient()
    {
        $apiKey = config('services.openai.key');

        if (! $apiKey) {
            throw new Exception('OPENAI_API_KEY is not configured.');
        }

        return \OpenAI::client($apiKey);
    }

    private function currentPrompt(Request $request): string
    {
        if ($request->session()->has('patient_id')) {
            return 'What can I do for you today?';
        }

        if ($request->session()->has('pending_identification_number')) {
            return "I don't recognize that identification. What is your name?";
        }

        return 'Hello. Please enter your identification number to start.';
    }

    private function assistantMessage(string $content): array
    {
        return [
            'id' => (string) Str::uuid(),
            'role' => 'assistant',
            'content' => $content,
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function patientName(Patient $patient): string
    {
        return $patient->name ?: 'there';
    }
}
