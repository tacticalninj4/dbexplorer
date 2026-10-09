<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What a schedule's creator (or its mail recipients) receive when a
 * scheduled query finishes (PRD §7.9). Mail only in v1: the host can bind
 * its own notifiable for other channels.
 */
final class ScheduledQueryFinished extends Notification
{
    /**
     * @param  'succeeded'|'failed'  $status
     */
    public function __construct(
        public string $scheduleName,
        public string $status,
        public ?int $rows,
        public ?float $durationMs,
        public ?string $error,
    ) {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $failed = $this->status === 'failed';

        $mail = (new MailMessage)
            ->subject('DbExplorer schedule '.$this->scheduleName.': '.$this->status)
            ->greeting($failed ? 'The scheduled query failed.' : 'The scheduled query ran.')
            ->line('Schedule: '.$this->scheduleName);

        if ($this->rows !== null) {
            $mail->line('Rows: '.$this->rows);
        }

        if ($this->durationMs !== null) {
            $mail->line('Duration: '.round($this->durationMs, 1).' ms');
        }

        if ($this->error !== null && $this->error !== '') {
            $mail->line('Error: '.$this->error);
        }

        return $mail->line('Open DbExplorer to see the run and its audit entry.');
    }
}
