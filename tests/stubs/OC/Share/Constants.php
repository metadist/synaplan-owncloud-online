<?php

declare(strict_types=1);

namespace OC\Share;

/**
 * Stub of ownCloud's private OC\Share\Constants, which OCP\Share extends and
 * takes its share types and states from.
 */
class Constants
{
    public const SHARE_TYPE_USER = 0;
    public const SHARE_TYPE_GROUP = 1;
    public const SHARE_TYPE_LINK = 3;
    public const SHARE_TYPE_GUEST = 4;
    public const SHARE_TYPE_REMOTE = 6;
    public const SHARE_TYPE_REMOTE_GROUP = 7;

    public const STATE_ACCEPTED = 0;
    public const STATE_PENDING = 1;
    public const STATE_REJECTED = 2;
}
