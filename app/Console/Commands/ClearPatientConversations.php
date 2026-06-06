<?php

namespace App\Console\Commands;

use App\Models\Patient;
use Illuminate\Console\Command;

class ClearPatientConversations extends Command
{
    protected $signature = 'medichat:clear-conversation {identification : Patient identification number}';

    protected $description = 'Clear all stored conversations for a patient identification.';

    public function handle(): int
    {
        $identification = trim((string) $this->argument('identification'));
        $patient = Patient::where('identification_number', $identification)->first();

        if (! $patient) {
            $this->error("No patient found with identification [{$identification}].");

            return self::FAILURE;
        }

        $conversationCount = $patient->conversations()->count();
        $messageCount = $patient->conversations()
            ->withCount('messages')
            ->get()
            ->sum('messages_count');
        $aiRequestCount = $patient->aiRequests()
            ->whereNotNull('conversation_id')
            ->count();

        $patient->conversations()->delete();

        $this->info("Deleted {$conversationCount} conversation(s) for {$patient->identification_number}.");
        $this->line("Deleted {$messageCount} message(s) through cascade rules.");
        $this->line("Kept {$aiRequestCount} AI request audit row(s); their conversation reference is cleared by the database.");
        $this->line('The patient record was kept.');

        return self::SUCCESS;
    }
}
