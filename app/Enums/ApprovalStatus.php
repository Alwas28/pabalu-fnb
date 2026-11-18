<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'badge-amber',
            self::Approved => 'badge-green',
            self::Rejected => 'badge-red',
        };
    }
}
