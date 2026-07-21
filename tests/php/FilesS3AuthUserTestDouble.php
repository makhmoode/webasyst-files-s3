<?php

/**
 * Avoid waUser DB load in unit tests.
 */
class filesS3AuthUserTestDouble extends filesS3AuthUser
{
    /**
     * @var int
     */
    protected $stub_id;

    /**
     * @param int $id
     */
    public function __construct($id)
    {
        $this->stub_id = (int) $id;
    }

    public function getId($load = true)
    {
        return $this->stub_id;
    }

    public function getLocale()
    {
        return 'en_US';
    }
}
