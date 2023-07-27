<?php

namespace app\v1\sms\struct;

class SendStdErr
{
    protected int $code;
    protected mixed $data = [];
    protected string|null $error = null;

    public function __construct(int $code, $data = [], $error = null)
    {
        $this->code = $code;
        $this->data = $data;
        $this->error = $error;
    }

    public function getCode()
    {
        return $this->code;
    }

    public function getData()
    {
        return $this->data;
    }

    public function isSuccess(): bool
    {
        if ($this->code === 0) {
            return true;
        }
        return empty($this->error);
    }

    /**
     * @return mixed
     */
    public function getError(): mixed
    {
        return $this->error;
    }
}