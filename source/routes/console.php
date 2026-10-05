<?php
use Illuminate\Support\Facades\{Artisan,Schedule};
use App\Models\{JourneyToken,Journey,JourneyVersion};
use App\Jobs\ExecuteJourneyNodeJob;
Schedule::call(function(){
 JourneyToken::whereIn('status',['pending','waiting'])->where(fn($q)=>$q->whereNull('resume_at')->orWhere('resume_at','<=',now()))->whereHas('subscriber.journey',fn($q)=>$q->where('status','active'))->limit(500)->get()->each(fn($t)=>ExecuteJourneyNodeJob::dispatch($t->id));
 JourneyToken::where('status','waiting')->whereHas('subscriber.journey',fn($q)=>$q->where('status','active'))->limit(500)->get()->each(function($t){$sub=$t->subscriber;$graph=JourneyVersion::where('journey_id',$sub->journey_id)->where('version',$sub->version)->first()->graph;$n=collect($graph['nodes'])->firstWhere('id',$t->node_uuid);if(($n['data']['key']??'')==='wait_until'&&app(\App\Services\NodeHandlers::class)->matches($sub->contact,$n['data']['settings'])){$t->update(['resume_at'=>now()]);ExecuteJourneyNodeJob::dispatch($t->id);}});
 foreach(Journey::where('status','active')->get()as $j){$graph=JourneyVersion::where('journey_id',$j->id)->where('version',$j->published_version)->first()->graph;foreach($graph['nodes']as $n){$key=$n['data']['key'];if(!in_array($key,['cyclic','date_field']))continue;$settings=$n['data']['settings'];$minute=(int)floor(time()/60);if($key==='cyclic'&&$minute%max(1,(int)$settings['minutes'])!==0)continue;\App\Models\Contact::whereHas('audiences',fn($q)=>$q->whereIn('audiences.id',$j->audiences()->pluck('audiences.id')))->chunkById(100,function($contacts)use($key,$settings,$j,$minute){foreach($contacts as $c){if($key==='date_field'&&data_get($c->fields,$settings['field'])!==now()->toDateString())continue;app(\App\Services\JourneyExecutionEngine::class)->enroll($j,$c,$key.':'.($key==='cyclic'?$minute:now()->toDateString()),$key);}});}}
})->everyMinute()->name('ma-dispatch')->withoutOverlapping();

Schedule::command('voice:followups:run')->everyMinute()->name('voice-followups')->withoutOverlapping(5);

Schedule::command('ma:inbox-sync')->everyMinute()->name('ma-inbox')->withoutOverlapping(5);

Schedule::command('ma:events-deliver')->everyMinute()->name('ma-events')->withoutOverlapping(5);
