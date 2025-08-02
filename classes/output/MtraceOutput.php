<?php

namespace local_cleanup\output;

class MtraceOutput implements OutputInterface
{
    public function write(string $message)
    {
        mtrace($message, null);
    }

    public function writeLine(string $message)
    {
        mtrace($message);
    }
}
