<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Hl7Message extends Model
{
    use BelongsToLaboratory, HasUuids;
    protected $fillable = ['laboratory_id', 'message_control_id', 'message_type', 'raw_message', 'status', 'error_log'];
}