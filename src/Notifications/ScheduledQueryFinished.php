<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What a schedule's creator (or its mail recipients) receive when a
 * scheduled query finishes (PRD §7.9). Mail only in v1: the host can bind
 * its own notifiable for other channels.
 *
 * The schedule's name and the error come from users and the database, and
 * mail lines are rendered as Markdown: they are escaped to plain text, so
 * a name like `[Reset password](https://…)` can't become a link (F38).
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
            ->line('Schedule: '.self::plain($this->scheduleName));

        if ($this->rows !== null) {
            $mail->line('Rows: '.$this->rows);
        }

        if ($this->durationMs !== null) {
            $mail->line('Duration: '.round($this->durationMs, 1).' ms');
        }

        if ($this->error !== null && $this->error !== '') {
            $mail->line('Error: '.self::plain($this->error));
        }

        return $mail->line('Open DbExplorer to see the run and its audit entry.');
    }

    /**
     * $text as Markdown that renders as itself: one line, with every
     * Markdown punctuation character backslash-escaped. HTML's own special
     * characters are left to the mail template, which escapes them.
     */
    public static function plain(string $text): string
    {
        return (string) preg_replace('/[\\\\`*_{}\[\]()#+\-.!|~]/', '\\\\$0', (string) preg_replace('/\s+/', ' ', $text));
    }
}
