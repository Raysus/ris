<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ReportTemplate extends Model
{
    use HasUuids;
    protected $fillable = ['laboratory_id', 'group_code', 'title', 'content'];
}