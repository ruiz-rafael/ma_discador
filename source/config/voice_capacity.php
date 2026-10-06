<?php
// Keep production admission at one until media and carrier concurrency are homologated.
return ['simultaneous_calls'=>(int)env('MA_VOICE_SIMULTANEOUS_CALLS',1)];
