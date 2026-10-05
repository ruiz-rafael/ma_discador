<?php
namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ListImports
{
    public function read(UploadedFile $file, string $delimiter): array
    {
        abort_unless(in_array($delimiter, [',', ';', "\t"]), 422, 'Separador inválido.');
        abort_unless(strtolower($file->getClientOriginalExtension()) === 'csv' && $file->getSize() <= 2097152, 422, 'Envie CSV com até 2 MB.');
        $f = fopen($file->getRealPath(), 'r'); $rows = [];
        try {
            while (($row = fgetcsv($f, 0, $delimiter, '"', '')) !== false) {
                abort_if(count($row) > 50 || count($rows) >= 1001, 422, 'Limite de 1.000 linhas e 50 colunas.');
                foreach ($row as &$value) { $value ??= ''; abort_unless(mb_check_encoding($value, 'UTF-8') && strlen($value) <= 10000, 422, 'Use UTF-8 e campos com até 10.000 bytes.'); } unset($value);
                $rows[] = $row;
            }
        } finally { fclose($f); }
        abort_unless(count($rows) > 1, 422, 'Informe cabeçalho e ao menos uma linha.');
        $headers = array_map(fn ($v) => trim(ltrim($v, "\xEF\xBB\xBF")), array_shift($rows));
        abort_if(in_array('', $headers) || count(array_unique($headers)) !== count($headers), 422, 'Os cabeçalhos precisam ser preenchidos e diferentes.');
        foreach ($headers as $h) abort_if(mb_strlen($h) > 160, 422, 'Cabeçalho excessivo.');
        return ['headers' => $headers, 'rows' => $rows];
    }
    public function preview(int $w, int $u, string $kind, int $id, UploadedFile $file, array $d): array
    {
        $service = app(ListContacts::class); $service->list($w, $kind, $id); $schema = $service->settings($w, $kind, $id);
        $csv = $this->read($file, $d['delimiter']); $mapping = $service->mapping($d['mapping'], $schema['fields']);
        foreach ($mapping as $m) abort_unless(in_array($m['source'], $csv['headers']), 422, 'Coluna ausente: '.$m['source']);
        $rows = []; $errors = []; $seen = [];
        foreach ($csv['rows'] as $i => $cells) {
            if (! array_filter($cells, fn ($v) => trim($v) !== '')) continue;
            try {
                abort_unless(count($cells) === count($csv['headers']), 422, 'Quantidade de colunas diferente do cabeçalho.');
                $data = $service->map(array_combine($csv['headers'], $cells), $mapping);
                foreach (['consent', 'consent_evidence', 'source'] as $key) if (! array_key_exists($key, $data)) $data[$key] = $d[$key] ?? null;
                $data = $service->normalize($kind, $schema['fields'], $data);
                $identities = array_filter([$data['phone'] ?? null, $data['email'] ?? null]);
                foreach ($identities as $identity) abort_if(isset($seen[$identity]), 422, 'Contato repetido neste arquivo.');
                foreach ($identities as $identity) $seen[$identity] = true;
                $rows[] = $data;
            } catch (ValidationException|HttpException $e) {
                $errors[] = ['line' => $i + 2, 'message' => $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage()];
            }
        }
        $batch = (string) Str::uuid(); $summary = ['valid' => count($rows), 'invalid' => count($errors), 'created' => 0, 'preserved' => 0, 'added' => 0, 'removed_preserved' => 0,'outside_rules'=>0];
        DB::table('ma_list_imports')->insert(['id' => $batch, 'workspace_id' => $w, 'kind' => $kind, 'list_id' => $id, 'list_revision' => $schema['revision'], 'user_id' => $u, 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'rows' => json_encode($rows), 'errors' => json_encode($errors), 'summary' => json_encode($summary), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        return $this->public(DB::table('ma_list_imports')->find($batch));
    }
    public function public(object $batch): array
    {
        return ['id' => $batch->id, 'status' => $batch->status, 'summary' => json_decode($batch->summary, true), 'preview' => array_slice(json_decode($batch->rows, true), 0, 20), 'errors' => array_slice(json_decode($batch->errors, true), 0, 100)];
    }
    public function commit(int $w, string $kind, int $list, string $id): array
    {
        return DB::transaction(function () use ($w, $kind, $list, $id) {
            $service = app(ListContacts::class); $service->lock(); $service->list($w, $kind, $list);
            $b = DB::table('ma_list_imports')->where('workspace_id', $w)->where('kind', $kind)->where('list_id', $list)->where('id', $id)->firstOrFail();
            if ($b->status === 'committed') return $this->public($b);
            abort_if(now()->gt($b->expires_at), 410, 'Prévia expirada. Reenvie o arquivo.');
            abort_unless($service->settings($w, $kind, $list)['revision'] === $b->list_revision, 409, 'Os campos da lista mudaram. Gere uma nova prévia.');
            $summary = json_decode($b->summary, true); abort_unless($summary['valid'] > 0, 422, 'Não há linhas válidas para importar.');
            foreach (json_decode($b->rows, true) as $row) {
                $result = $service->ingest($w, $kind, $list, $row, false);
                foreach (['created', 'preserved', 'added'] as $key) $summary[$key] += (int) $result[$key];
                $summary['outside_rules']=($summary['outside_rules']??0)+(int)($result['outside_rules']??false);
                $summary['removed_preserved'] += (int) $result['membership_removed'];
            }
            app(Segments::class)->refresh($w,$kind,$list);
            DB::table('ma_list_imports')->where('id', $id)->update(['status' => 'committed', 'summary' => json_encode($summary), 'updated_at' => now()]);
            return $this->public(DB::table('ma_list_imports')->find($id));
        });
    }
}
