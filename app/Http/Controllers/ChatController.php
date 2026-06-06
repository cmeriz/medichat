<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Models\AiRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Patient;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $content = trim($validated['content']);
        $reply = $this->handleIncomingMessage($request, $content);

        broadcast(new ChatMessageSent(
            $request->session()->getId(),
            $this->assistantMessage($reply),
        ));

        return response()->json([
            'ok' => true,
        ]);
    }

    private function handleIncomingMessage(Request $request, string $content): string
    {
        if ($request->session()->get('patient_id')) {
            return $this->answerWithAi($request, $content);
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

    private function answerWithAi(Request $request, string $content): string
    {
        $conversation = Conversation::query()
            ->whereKey($request->session()->get('conversation_id'))
            ->firstOrFail();

        $currentUserMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $content,
        ]);

        try {
            $reply = $this->createChatCompletion($conversation, $currentUserMessage);
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
            'prompt_messages' => $messages,
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
