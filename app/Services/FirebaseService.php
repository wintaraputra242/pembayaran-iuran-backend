<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(storage_path('app/firebase/firebase_credentials.json'));

        $this->messaging = $factory->createMessaging();
    }

    public function send($token, $title, $body)
    {
        $message = CloudMessage::withTarget('token', $token)
            ->withNotification(Notification::create($title, $body));

        return $this->messaging->send($message);
    }

    public function sendBulk(array $tokens, string $title, string $body)
    {
        if (empty($tokens)) {
            return false;
        }

        $notification = Notification::create($title, $body);

        $messages = [];

        foreach ($tokens as $token) {
            $messages[] = CloudMessage::withTarget('token', $token)
                ->withNotification($notification);
        }

        return $this->messaging->sendAll($messages);
    }
}
