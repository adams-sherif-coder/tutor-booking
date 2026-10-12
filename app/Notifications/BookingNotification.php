<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public array $snapshot;

    public function __construct(Booking $booking, public string $event)
    {
        $this->snapshot = ['booking_id' => $booking->id, 'event' => $event, 'status' => $booking->status, 'starts_at' => $booking->starts_at->toIso8601String(), 'ends_at' => $booking->ends_at->toIso8601String()];
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->snapshot;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Booking '.$this->event)->line('Booking #'.$this->snapshot['booking_id'].' was '.$this->event.'.')->line('Starts: '.$this->snapshot['starts_at'].' (UTC)')->line('Ends: '.$this->snapshot['ends_at'].' (UTC)');
    }
}
