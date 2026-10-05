<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class VoiceJourneyOverview
{
    public function get(int $w): array
    {
        return DB::table('voice_campaigns')->where('workspace_id', $w)->orderByDesc('id')->limit(100)->get()->map(function ($c) {
            $s = json_decode($c->settings, true);
            $policy = DB::table('voice_campaign_policies')->where('campaign_id', $c->id)->first();
            $list = $policy?->list_id ? DB::table('voice_lists')->where('id', $policy->list_id)->first(['id', 'name']) : null;
            if ($list) $list->kind = 'voice';
            if ($policy?->audience_id && $c->workspace_id === 1) {
                $list = DB::table('audiences')->where('id', $policy->audience_id)->first(['id', 'name']);
                if ($list) $list->kind = 'automation';
            }
            $queue = QueueRouting::forCampaign($c->workspace_id,$c->id)->first(['id', 'name', 'mode', 'status','voice_number','whatsapp_sender_id','number_mode','channels_configured','calling_method','workspace_id']);
            $s=app(QueueChannels::class)->apply($queue,$s);
            $template = empty($s['whatsapp_real_template_id']) ? null : DB::table('wa_templates')->where('workspace_id', $c->workspace_id)->where('id', $s['whatsapp_real_template_id'])->first(['id', 'name', 'body', 'approval_status']);
            $sender = empty($s['whatsapp_sender_id']) ? null : DB::table('wa_senders')->where('workspace_id', $c->workspace_id)->where('id', $s['whatsapp_sender_id'])->first(['id', 'label', 'number', 'provider', 'status']);

            $layout = DB::table('voice_journey_layouts')->where('campaign_id', $c->id)->first();

            return ['revision' => $c->revision, 'layout' => ['revision' => $layout?->revision ?? 0, 'positions' => $layout ? json_decode($layout->positions, true) : []], 'id' => $c->id, 'name' => $c->name, 'updated_at' => $c->updated_at, 'status' => $c->status, 'settings' => $s, 'retry_minutes' => array_replace(array_fill_keys(['no_answer', 'busy', 'failed', 'cancelled'], $s['retry_minutes']), json_decode($policy?->retry_minutes ?? '{}', true)), 'list' => $list, 'queue' => $queue, 'template' => $template, 'sender' => $sender, 'contact_count' => DB::table('voice_members')->where('campaign_id', $c->id)->count()];
        })->all();
    }
}
