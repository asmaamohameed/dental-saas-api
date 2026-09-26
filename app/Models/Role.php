<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'label',
    ];

    public function clinicMembers(): BelongsToMany
    {
        return $this->belongsToMany(ClinicMember::class, 'clinic_member_role');
    }
}
