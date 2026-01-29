<?php

namespace App\Services;

class AuthManagerService {
    public function validateSession($token) {
        return true;
    }
}
