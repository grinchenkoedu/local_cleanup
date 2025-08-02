<?php

namespace local_cleanup\output;

interface OutputInterface
{
    public function write(string $message);
    public function writeLine(string $message);
}
