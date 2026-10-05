<?php
namespace App\Http\Controllers;

use App\Services\ChannelCosts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PublicReadController extends Controller
{
    private const ZONE = 'America/Sao_Paulo';
    private const CONVERSATION_FIELDS = ['id', 'sender_id', 'contact_id', 'phone', 'queue_id', 'assigned_user_id', 'status', 'last_message_at', 'last_inbound_at', 'revision', 'created_at', 'updated_at'];
    private const CALL_FIELDS = ['id', 'queue_id', 'user_id', 'from_number', 'to_number', 'status', 'created_at', 'answered_at', 'ended_at', 'capacity_released_at', 'bill_seconds', 'disposition_code'];

    private function workspace(Request $request): int
    {
        return $request->attributes->get('integration')->workspace_id;
    }

    private function period(Request $request, array $extra = []): array
    {
        $data = $request->validate($extra + [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'page' => 'nullable|integer|between:1,10000',
        ]);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 89, 422, 'Use um período de até 90 dias.');
        return $data;
    }

    private function scopedReference(int $workspace, string $table, string $key, ?int $id): void
    {
        if ($id !== null) {
            DB::table($table)->where($key, $workspace)->where('id', $id)->firstOrFail();
        }
    }

    public function inbound(Request $request)
    {
        $workspace = $this->workspace($request);
        $data = $this->period($request, ['queue_id' => 'nullable|integer|min:1', 'user_id' => 'nullable|integer|min:1']);
        $this->scopedReference($workspace, 'voice_live_queues', 'workspace_id', $data['queue_id'] ?? null);
        $this->scopedReference($workspace, 'users', 'voice_workspace_id', $data['user_id'] ?? null);
        $rows = DB::table('voice_inbound_calls')->where('workspace_id', $workspace)
            ->where('created_at', '>=', CarbonImmutable::parse($data['from'], self::ZONE)->utc())
            ->where('created_at', '<', CarbonImmutable::parse($data['to'], self::ZONE)->addDay()->utc())
            ->when($data['queue_id'] ?? null, fn ($q) => $q->where('queue_id', $data['queue_id']))
            ->when($data['user_id'] ?? null, fn ($q) => $q->where('user_id', $data['user_id']))
            ->orderByDesc('created_at')->orderBy('id')->paginate(50, self::CALL_FIELDS);
        $rows->getCollection()->transform(fn ($call) => $this->call($call));
        return ['rows' => $rows, 'timezone' => self::ZONE, 'scope' => 'Chamadas admitidas pelo MA; uma transferência não cria outra chamada neste total.'];
    }

    private function call(object $call): object
    {
        $call->outcome = $call->answered_at ? 'answered' : (!$call->capacity_released_at ? 'active' : ($call->status === 'unavailable' ? 'unavailable' : 'abandoned'));
        return $call;
    }

    public function inboundDetail(Request $request, string $id): array
    {
        $workspace = $this->workspace($request);
        $call = DB::table('voice_inbound_calls')->where('workspace_id', $workspace)->where('id', $id)->firstOrFail(self::CALL_FIELDS);
        return ['call' => $this->call($call), 'offers' => DB::table('voice_inbound_offers')->where('call_id', $id)
            ->orderBy('created_at')->orderBy('id')->get(['id', 'user_id', 'status', 'created_at', 'answered_at', 'ended_at'])];
    }

    public function conversations(Request $request)
    {
        $workspace = $this->workspace($request);
        $data = $request->validate(['status' => 'nullable|in:open,closed', 'sender_id' => 'nullable|integer|min:1', 'assigned_user_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|between:1,10000']);
        $this->scopedReference($workspace, 'wa_senders', 'workspace_id', $data['sender_id'] ?? null);
        $this->scopedReference($workspace, 'users', 'voice_workspace_id', $data['assigned_user_id'] ?? null);
        return DB::table('wa_conversations')->where('workspace_id', $workspace)
            ->when($data['status'] ?? null, fn ($q) => $q->where('status', $data['status']))
            ->when($data['sender_id'] ?? null, fn ($q) => $q->where('sender_id', $data['sender_id']))
            ->when($data['assigned_user_id'] ?? null, fn ($q) => $q->where('assigned_user_id', $data['assigned_user_id']))
            ->orderByDesc('updated_at')->orderBy('id')->paginate(50, self::CONVERSATION_FIELDS);
    }

    public function messages(Request $request, string $id): array
    {
        $workspace = $this->workspace($request);
        $request->validate(['page' => 'nullable|integer|between:1,10000']);
        $conversation = DB::table('wa_conversations')->where('workspace_id', $workspace)->where('id', $id)->firstOrFail(self::CONVERSATION_FIELDS);
        $messages = DB::table('wa_messages')->where('workspace_id', $workspace)->where('conversation_id', $id)
            ->orderByDesc('created_at')->orderBy('id')->paginate(50, ['id', 'direction', 'provider', 'status', 'body', 'created_at', 'updated_at', 'campaign_id', 'user_id', 'button_id', 'button_label', 'reply_to_message_id', 'reply_attribution']);
        return ['conversation' => $conversation, 'messages' => $messages];
    }

    public function costs(Request $request): array
    {
        $data = $this->period($request, ['channel' => ['nullable', Rule::in(ChannelCosts::CHANNELS)]]);
        $report = app(ChannelCosts::class)->report($this->workspace($request), $data['from'], $data['to'], $data['channel'] ?? '', $data['page'] ?? 1);
        $report['rows']['data'] = $report['rows']['data']->map(function (array $row) { unset($row['can_sync']); return $row; });
        return $report;
    }
}
