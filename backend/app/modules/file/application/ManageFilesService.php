<?php

declare(strict_types=1);
namespace app\modules\file\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\application\storage\StorageChannelService;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\file\contract\FileRegistryInterface;
use app\shared\http\ApiErrorCode;
use app\modules\file\validation\FileInputValidator;
use app\modules\file\validation\OfficeFileValidator;
use app\shared\plugin\dto\storage\{ObjectKey, ObjectUpload};
use app\shared\validation\PublicImage;

/** 统一文件操作；公开素材和私密业务附件不混用权限。 */
final readonly class ManageFilesService
{
    public const MAX_BYTES = 20971520;
    public function __construct(private FileRegistryInterface $files, private AdminAccessControlService $access, private ChannelRepositoryInterface $channels, private StorageChannelService $storage, private FileInputValidator $validator = new FileInputValidator()) {}
    public function list(AdminAccount $actor, array $filters): array
    {
        $this->access->requirePermission($actor, 'admin.files.view');
        return $this->files->paginate($this->validator->query($filters));
    }
    public function statistics(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.files.view');
        return $this->files->statistics();
    }
    public function authorizeUpload(AdminAccount $actor): void
    {
        $this->access->requirePermission($actor, 'admin.files.view');
        $this->access->requirePermission($actor, 'admin.files.upload');
    }
    public function upload(AdminAccount $actor, string $bytes, string $filename): array
    {
        $this->authorizeUpload($actor);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) $this->invalid('file');
        $name = mb_substr(basename(str_replace('\\', '/', $filename)), 0, 200);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allow = ['pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'md' => ['text/plain'], 'csv' => ['text/plain','text/csv'], 'zip' => ['application/zip','application/x-zip'], 'mp4' => ['video/mp4'], 'mp3' => ['audio/mpeg','audio/mp3']];
        if (in_array($extension, ['jpg','jpeg','png','gif','webp'], true)) {
            $image = PublicImage::validate($bytes, $name); $mime = $image['mime']; $extension = $image['extension']; $type = 'image';
        } elseif (in_array($extension, ['docx', 'xls', 'xlsx'], true)) {
            $mime = (new OfficeFileValidator())->validate($bytes, $extension, (string) $mime);
            $type = 'document';
        } elseif (in_array($extension, ['sql', 'db', 'sqlite', 'sqlite3'], true)) {
            $this->validateDatabaseFile($bytes, $extension, $mime);
            $type = 'other';
        } else {
            if (!in_array($mime, $allow[$extension] ?? [], true)) throw new BusinessException('文件类型不支持或内容与扩展名不符', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['file' => ['支持图片、PDF、DOCX、XLS、XLSX、TXT、Markdown、CSV、ZIP、MP4、MP3、SQL和SQLite，禁止上传可执行文件']]]);
            $type = match ($extension) { 'mp4' => 'video', 'mp3' => 'audio', 'zip' => 'other', default => 'document' };
        }
        $channel = null;
        foreach ($this->channels->list('storage', ['page'=>1,'page_size'=>100,'status'=>1])['data'] as $row) if ($row['is_default'] && $row['configuration_current'] && $row['platform_available']) { $channel = $row; break; }
        if ($channel === null) throw new BusinessException('请先配置并启用默认存储通道', ApiErrorCode::CONFLICT, 409);
        $id = bin2hex(random_bytes(16)); $key = new ObjectKey('files/' . $id . '.' . $extension);
        $file = ['id'=>$id,'original_name'=>$name,'extension'=>$extension,'type'=>$type,'namespace'=>'files','visibility'=>'public','origin'=>'library','mime_type'=>$mime,'byte_size'=>strlen($bytes),'channel_id'=>$channel['id'],'channel_revision'=>$channel['revision'],'object_key'=>$key->value,'created_by'=>$actor->id,'status'=>'pending','created_at'=>date('Y-m-d H:i:s')];
        $this->files->reserve($file);
        try { $result = $this->storage->put($channel['id'], $channel['revision'], new ObjectUpload($key, $bytes, $mime)); }
        catch (\Throwable $error) { $this->files->mark($id, 'unknown'); throw $error; }
        $this->files->mark($id, $result->status);
        if ($result->status !== 'stored') throw new BusinessException('文件上传未完成，请检查存储通道', ApiErrorCode::CONFLICT, 409);
        return ['id'=>$id,'url'=>'/api/public/v1/files/'.$id,'name'=>$name,'type'=>$type];
    }
    public function delete(AdminAccount $actor, array $ids): array
    {
        $this->access->requirePermission($actor, 'admin.files.view'); $this->access->requirePermission($actor, 'admin.files.delete');
        $rows = $this->files->beginDelete($this->validator->deletion(['ids' => $ids])); $deleted = []; $failed = [];
        foreach ($rows as $row) {
            try { $confirmed = $this->storage->delete((int)$row['channel_id'], (int)$row['channel_revision'], new ObjectKey($row['object_key']))->status === 'deleted'; }
            catch (\Throwable $error) { $this->files->finishDelete($row['id'], false); foreach ($rows as $remaining) if (!in_array($remaining['id'], [...$deleted, ...$failed, $row['id']], true)) $this->files->finishDelete($remaining['id'], false); throw $error; }
            $this->files->finishDelete($row['id'], $confirmed);
            if ($confirmed) $deleted[] = $row['id']; else $failed[] = $row['id'];
        }
        return ['deleted'=>$deleted,'failed'=>$failed];
    }
    public function publicUrl(string $id): string
    {
        $file = $this->files->find($id);
        if ($file === null || $file['status'] !== 'stored' || $file['visibility'] !== 'public' || $file['deleted_at'] !== null) throw new BusinessException('文件不存在或不可访问', ApiErrorCode::NOT_FOUND, 404);
        return $this->storage->signedUrl((int)$file['channel_id'], (int)$file['channel_revision'], new ObjectKey($file['object_key']))->url;
    }
    /** 数据库素材仅存储；不执行SQL，也不打开上传的数据库。
     * @param string $bytes     上传内容
     * @param string $extension 小写扩展名
     * @param string $mime      服务端识别的MIME
     * @return void
     * @throws BusinessException 内容与格式不符
     */
    private function validateDatabaseFile(string $bytes, string $extension, string $mime): void
    {
        $valid = $extension === 'sql'
            ? in_array($mime, ['text/plain', 'text/x-sql', 'application/sql'], true) && !str_contains($bytes, "\0")
            : in_array($mime, ['application/vnd.sqlite3', 'application/x-sqlite3', 'application/octet-stream'], true)
                && strlen($bytes) >= 100 && str_starts_with($bytes, "SQLite format 3\0");
        if (!$valid) {
            throw new BusinessException('数据库文件内容与格式不符', ApiErrorCode::VALIDATION_FAILED, 422, [
                'errors' => ['file' => ['SQL须为文本文件；DB、SQLite和SQLite3须为SQLite数据库文件']],
            ]);
        }
    }
    private function invalid(string $field): never { throw new BusinessException('文件参数不符合要求', ApiErrorCode::VALIDATION_FAILED, 422, ['errors'=>[$field=>['请检查文件或查询条件']]]); }
}
