<?php

namespace App\Models;

use App\Models\User;
use App\Support\Traits\BlameableTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use BlameableTrait;

    // role_has_permissions sengaja dihapus (migration 2026_08_03_104519) karena fitur
    // permission granular Spatie tidak dipakai — override no-op ini mencegah HasPermissions
    // trait (dari parent SpatieRole) mencoba DELETE ke tabel yang sudah tidak ada saat role dihapus.
    public static function bootHasPermissions()
    {
    }

    protected $table = 'tb_role';

    protected $fillable = [
        'name',
        'guard_name',
        'nama_role',
        'keterangan',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(\Spatie\Permission\PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key')
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
