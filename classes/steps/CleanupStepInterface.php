<?php

namespace local_cleanup\steps;

use local_cleanup\output\OutputInterface;

interface CleanupStepInterface
{
    public function cleanUp(OutputInterface $output);
}
