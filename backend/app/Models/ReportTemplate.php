<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ReportTemplate extends Model
{
    protected $fillable = ['laboratory_id', 'group_code', 'title', 'content'];
}