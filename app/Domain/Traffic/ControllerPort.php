<?php

namespace App\Domain\Traffic;

interface ControllerPort
{
    public function send(ControllerCommand $command): void;
}
