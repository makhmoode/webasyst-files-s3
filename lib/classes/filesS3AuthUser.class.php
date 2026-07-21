<?php

/**
 * Authenticated user for S3 API without PHP session / wa_contact_auths.
 */
class filesS3AuthUser extends waUser
{
    /**
     * @param int|string $id
     */
    public function __construct($id)
    {
        parent::__construct($id);
    }

    /**
     * @return bool
     */
    public function isAuth()
    {
        return (bool) $this->getId();
    }
}
