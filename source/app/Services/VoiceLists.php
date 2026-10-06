<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VoiceLists
{
    private function xml(\ZipArchive $z, string $name, int $limit = 12000000): \SimpleXMLElement
    {
        $stat = $z->statName($name);
        abort_unless($stat && $stat['size'] <= $limit, 422, 'Planilha ausente ou excessiva.');
        $text = $z->getFromName($name);
        abort_if(stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false, 422, 'XML com entidades não é permitido.');
        $xml = @simplexml_load_string($text, \SimpleXMLElement::class, LIBXML_NONET);
        abort_unless($xml, 422, 'Planilha XML inválida.');

        return $xml;
    }

    private function read(UploadedFile $file, string $delimiter): array
    {
        $rows = [];
        if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
            $z = new \ZipArchive;
            abort_unless($z->open($file->getRealPath()) === true, 422, 'Arquivo XLSX inválido.');
            try {
                $total = 0;
                for ($i = 0; $i < $z->numFiles; $i++) {
                    $total += $z->statIndex($i)['size'];
                    abort_if($total > 32000000 || $z->numFiles > 1000, 422, 'Planilha expandida excessiva.');
                }
                $shared = [];
                if ($z->locateName('xl/sharedStrings.xml') !== false) {
                    $x = $this->xml($z, 'xl/sharedStrings.xml');
                    foreach ($x->xpath('//*[local-name()="si"]') as $si) {
                        $shared[] = implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
                    }
                }
                $workbook = $this->xml($z, 'xl/workbook.xml');
                $sheets = $workbook->xpath('//*[local-name()="sheet"]');
                abort_unless($sheets, 422, 'Planilha sem abas.');
                $rid = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                $rels = $this->xml($z, 'xl/_rels/workbook.xml.rels');
                $target = null;
                foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) {
                    if ((string) $rel['Id'] === $rid && empty((string) $rel['TargetMode'])) {
                        $target = (string) $rel['Target'];
                    }
                }
                abort_unless($target && ! str_contains($target, '..') && ! str_contains($target, ':'), 422, 'Referência de aba inválida.');
                $sheet = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                $x = $this->xml($z, $sheet);
                foreach ($x->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                    $values = [];
                    foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                        $address = (string) $cell['r'];
                        abort_unless(preg_match('/^([A-Z]{1,3})[0-9]+$/', $address, $m), 422, 'Endereço de célula inválido.');
                        $index = 0;
                        foreach (str_split($m[1]) as $ch) {
                            $index = $index * 26 + ord($ch) - 64;
                        }abort_if($index > 100, 422, 'Máximo de 100 colunas.');
                        $type = (string) $cell['t'];
                        $val = (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                        if ($type === 's') {
                            $val = $shared[(int) $val] ?? '';
                        }if ($type === 'inlineStr') {
                            $val = implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]')));
                        }if ($cell->xpath('./*[local-name()="f"]')) {
                            $val = '=FORMULA';
                        }$values[$index - 1] = $val;
                    }
                    if ($values) {
                        $data = array_fill(0, max(array_keys($values)) + 1, '');
                        foreach ($values as $i => $v) {
                            $data[$i] = $v;
                        }$rows[] = $data;
                    }abort_if(count($rows) > 1001, 422, 'Máximo de 1.000 contatos por arquivo.');
                }
            } finally {
                $z->close();
            }
        } else {
            $f = fopen($file->getRealPath(), 'r');
            try {
                while (($line = fgetcsv($f, 0, $delimiter, '"', '')) !== false) {
                    $rows[] = $line;
                    abort_if(count($rows) > 1001, 422, 'Máximo de 1.000 contatos por arquivo.');
                }
            } finally {
                fclose($f);
            }
        }
        abort_unless(count($rows) >= 2, 422, 'Informe cabeçalho e ao menos um contato.');

        return $rows;
    }

    public function preview(int $w, int $u, int $list, UploadedFile $file, array $d): object
    {
        DB::table('voice_lists')->where('workspace_id', $w)->where('id', $list)->firstOrFail();
        $hash = hash('sha256', hash_file('sha256', $file->getRealPath()).json_encode($d));
        $old = DB::table('voice_imports')->where('list_id', $list)->where('file_hash', $hash)->first();
        if ($old) {
            return $this->publicBatch($old);
        }
        $raw = $this->read($file, $d['delimiter'] ?? ';');
        $headers = array_map(fn ($v) => trim(ltrim((string) $v, "\xEF\xBB\xBF")), array_shift($raw));
        $name = array_search($d['name_column'], $headers, true);
        $phone = array_search($d['phone_column'], $headers, true);
        abort_if($name === false || $phone === false, 422, 'Confira os nomes das colunas.');
        $rows = [];
        $errors = [];
        $seen = [];
        foreach ($raw as $index => $row) {
            if (count(array_filter($row, fn ($x) => trim((string) $x) !== '')) === 0) {
                continue;
            }$n = trim((string) ($row[$name] ?? ''));
            $p = trim((string) ($row[$phone] ?? ''));
            try {
                abort_unless($n !== '' && mb_strlen($n) <= 160 && preg_match('/^[+0-9 ()\.\-]{8,80}$/D', $p), 422, 'Nome ou telefone inválido; fórmulas e notação científica não são aceitas.');
                $normal = VoiceLab::phone($p);
                abort_if(isset($seen[$normal]), 422, 'Telefone repetido neste arquivo.');
                $seen[$normal] = true;
                $rows[] = ['name' => $n, 'phone' => $normal, 'original_phone' => $p, 'source' => $d['source'], 'consent' => (bool) $d['consent'], 'consent_evidence' => $d['consent'] ? $d['consent_evidence'] : null];
            } catch (HttpException|ValidationException $e) {
                $errors[] = ['line' => $index + 2, 'message' => $e->getMessage()];
            }
        }
        $id = (string) Str::uuid();
        $summary = ['valid' => count($rows), 'invalid' => count($errors), 'created' => 0, 'preserved' => 0, 'added' => 0];
        DB::table('voice_imports')->insertOrIgnore(['id' => $id, 'workspace_id' => $w, 'list_id' => $list, 'user_id' => $u, 'file_hash' => $hash, 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'rows' => json_encode($rows), 'errors' => json_encode($errors), 'summary' => json_encode($summary), 'created_at' => now(), 'updated_at' => now()]);

        return $this->publicBatch(DB::table('voice_imports')->where('list_id', $list)->where('file_hash', $hash)->firstOrFail());
    }

    public function publicBatch(object $b): object
    {
        $rows = json_decode($b->rows, true);
        $b->preview = array_slice($rows, 0, 20);
        unset($b->rows);
        $b->errors = array_slice(json_decode($b->errors, true), 0, 100);
        $b->summary = json_decode($b->summary, true);

        return $b;
    }

    public function commit(int $w, int $u, string $id): object
    {
        return DB::transaction(function () use ($w, $u, $id) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $b = DB::table('voice_imports')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
            if ($b->status === 'committed') {
                return $this->publicBatch($b);
            }$summary = json_decode($b->summary, true);
            foreach (json_decode($b->rows, true) as $row) {
                $c = DB::table('voice_contacts')->where('workspace_id', $w)->where('phone', $row['phone'])->first();
                if (! $c) {
                    $cid = DB::table('voice_contacts')->insertGetId($row + ['workspace_id' => $w, 'consented_at' => $row['consent'] ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
                    $summary['created']++;
                } else {
                    $cid = $c->id;
                    $summary['preserved']++;
                }
                $summary['added'] += DB::table('voice_list_members')->insertOrIgnore(['list_id' => $b->list_id, 'contact_id' => $cid, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (DB::table('voice_campaign_policies')->where('list_id', $b->list_id)->pluck('campaign_id') as $campaign) {
                foreach (DB::table('voice_list_members')->where('list_id', $b->list_id)->where('status', 'active')->pluck('contact_id') as $cid) {
                    DB::table('voice_members')->insertOrIgnore(['campaign_id' => $campaign, 'contact_id' => $cid]);
                }
            }
            DB::table('voice_imports')->where('id', $id)->update(['status' => 'committed', 'summary' => json_encode($summary), 'updated_at' => now()]);
            app(VoiceLab::class)->audit($w, $u, 'list.imported', $id, $summary);

            return $this->publicBatch(DB::table('voice_imports')->find($id));
        });
    }

    public function policy(int $w, int $u, int $campaignId, array $d): object
    {
        return DB::transaction(function () use ($w, $u, $campaignId, $d) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $c = DB::table('voice_campaigns')->where('workspace_id', $w)->where('id', $campaignId)->firstOrFail();
            abort_unless(in_array($c->status, ['draft', 'paused']), 409, 'Pause a campanha antes de alterar as regras.');
            abort_if(DB::table('voice_outbound_calls')->where('campaign_id', $campaignId)->whereNull('capacity_released_at')->exists() || DB::table('voice_followups')->where('campaign_id', $campaignId)->where('status', 'dispatching')->exists(), 409, 'Há chamada ou envio em andamento.');
            $old = DB::table('voice_campaign_policies')->where('campaign_id', $campaignId)->first();
            abort_unless(($old->revision ?? 0) === $d['revision'], 409, 'As regras mudaram. Atualize a tela.');
            unset($d['revision']);
            if (! empty($d['list_id'])) {
                abort_if($old?->audience_id, 422, 'Esta jornada usa uma lista do MA. Altere o público pelo cartão Público da jornada.');
                DB::table('voice_lists')->where('workspace_id', $w)->where('id', $d['list_id'])->firstOrFail();
                foreach (DB::table('voice_list_members')->where('list_id', $d['list_id'])->where('status', 'active')->pluck('contact_id') as $cid) {
                    DB::table('voice_members')->insertOrIgnore(['campaign_id' => $campaignId, 'contact_id' => $cid]);
                }
            }
            $defaultRetry = json_decode($c->settings, true)['retry_minutes'];
            $d['retry_minutes'] = json_encode((object) array_filter($d['retry_minutes'], fn ($minutes) => (int) $minutes !== (int) $defaultRetry));
            $d['expires_at'] = empty($d['expires_at']) ? null : CarbonImmutable::parse($d['expires_at'])->utc();
            DB::table('voice_campaign_policies')->updateOrInsert(['campaign_id' => $campaignId], $d + ['revision' => ($old->revision ?? 0) + 1, 'created_at' => $old->created_at ?? now(), 'updated_at' => now()]);
            DB::table('voice_campaigns')->where('id', $campaignId)->update(['revision' => $c->revision + 1, 'followup_revision' => $c->followup_revision + 1, 'updated_at' => now()]);
            DB::table('voice_followups')->where('campaign_id', $campaignId)->whereIn('status', ['pending', 'blocked'])->update(['status' => 'cancelled', 'reason' => 'Regras da campanha alteradas.', 'updated_at' => now()]);
            app(VoiceLab::class)->audit($w, $u, 'campaign.policy', $campaignId);

            return DB::table('voice_campaign_policies')->where('campaign_id', $campaignId)->first();
        });
    }

    public function member(int $w, int $u, int $id, string $status, string $reason): void
    {
        DB::transaction(function () use ($w, $u, $id, $status, $reason) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $m = DB::table('voice_list_members as m')->join('voice_lists as l', 'l.id', '=', 'm.list_id')->where('l.workspace_id', $w)->where('m.id', $id)->select('m.*')->firstOrFail();
            app(CadenceReentry::class)->membershipChanged('voice',$m->list_id,[$m->contact_id],$status==='active');
            DB::table('voice_list_members')->where('id',$id)->update(['status' => $status, 'reason' => $reason, 'updated_at' => now()]);
            if ($status === 'removed') {
                DB::table('voice_followups')->where('workspace_id',$w)->where('contact_id',$m->contact_id)->whereIn('campaign_id',DB::table('voice_campaign_policies')->where('list_id',$m->list_id)->select('campaign_id'))->whereIn('status',['pending', 'blocked'])->update(['status' => 'cancelled', 'reason' => 'Contato retirado da lista.', 'updated_at' => now()]);
            }app(VoiceLab::class)->audit($w,$u,'list.member.'.$status,$id,['reason' => $reason]);
        });
    }
}
