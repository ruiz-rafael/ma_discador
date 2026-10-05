<?php
namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Conservative attribution. Never count a body that resembles a button as a click. */
class JourneyReplyAttribution
{
    public function record(string $id): void
    {
        $m = DB::table('wa_messages')->where('id',$id)->where('direction','inbound')->firstOrFail();
        if ($m->reply_attribution !== null) return;
        $interactive = json_decode($m->interactive ?? '{}',true) ?? [];
        $q = DB::table('wa_messages')->where('workspace_id',$m->workspace_id)->where('sender_id',$m->sender_id)
            ->where('direction','outbound')->where('to_number',$m->from_number)->where('from_number',$m->to_number)
            ->where('provider',$m->provider)->where('created_at','<=',$m->created_at)
            ->whereNotIn('status',['sending','cancelled','failed']);
        $original = null; $kind = 'unattributed'; $button = null;
        $context = $interactive['context_id'] ?? null;
        $matched = $interactive['matched_message_id'] ?? null;
        if ($context || $matched) {
            $exact = clone $q;
            if ($matched) $exact->where('id',$matched);
            if ($context) $exact->where($m->provider==='qr'?'provider_reference':'provider_sid',$context);
            $original = $exact->first();
            if ($original) {
                $kind='context';
                if (($interactive['kind']??null)==='button_reply') {
                    $button=collect(json_decode($original->interactive??'{}',true)['buttons']??[])
                        ->first(fn($b)=>($b['type']??'')==='reply' && ($b['id']??null)===($interactive['id']??null));
                }
            }
        } elseif (($interactive['kind']??null)!=='button_reply') {
            $q->where('created_at','>=',CarbonImmutable::parse($m->created_at,'UTC')->subDays(7));
            // More than one journey (or a manual message) makes attribution ambiguous.
            $campaigns=(clone $q)->select('campaign_id')->distinct()->limit(2)->pluck('campaign_id');
            if ($campaigns->count()===1 && $campaigns->first()!==null) {
                $original=$q->orderByDesc('created_at')->orderBy('id')->first();$kind='single_journey';
            }
        }
        DB::table('wa_messages')->where('id',$id)->whereNull('reply_attribution')->update([
            'campaign_id'=>$original?->campaign_id,'reply_to_message_id'=>$original?->id,'reply_attribution'=>$kind,
            'button_id'=>$button['id']??null,'button_label'=>$button['label']??null,
        ]);
    }
}
