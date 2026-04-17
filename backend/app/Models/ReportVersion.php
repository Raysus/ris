<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ReportVersion extends Model
{
    protected $fillable = ['medical_report_id', 'user_id', 'action', 'report_text', 'ip_address'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}