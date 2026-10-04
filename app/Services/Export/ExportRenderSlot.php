<?php

namespace App\Services\Export;

class ExportRenderSlot
{
    private bool $released = false;

    /** @param resource $handle */
    public function __construct(private mixed $handle) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
