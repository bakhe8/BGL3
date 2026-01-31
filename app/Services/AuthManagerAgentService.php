<?php

namespace App\Services;

class AuthManagerAgentService {
    public function validateSession($token) {
        return true;
    }

    public function debugPing(): string
    {
        return 'pong';
    }
}
