<?php

namespace App\Controllers\File;

use App\Controllers\BaseController;
use App\Core\Security;

class UploadController extends BaseController
{
    public function upload()
    {
        $this->requireAuth();
        $this->validateCSRF();

        if (empty($_FILES['file'])) {
            $this->error('未选择文件');
        }

        $fileSize = $_FILES['file']['size'] ?? 0;
        $this->adaptiveRateLimit('upload', $fileSize);

        $parentId = intval($this->input('parent_id', 0));
        $conflictResolution = $this->input('conflict_resolution', null);

        $result = $this->fileManager()->uploadFile($parentId, $_FILES['file'], null, $conflictResolution);

        Security::jsonOutput($result);
    }

    public function uploadChunk()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $this->adaptiveRateLimit('upload_chunk');

        $parentId = intval($this->input('parent_id', 0));

        $chunkInfo = [
            'upload_id' => $this->input('upload_id', ''),
            'chunk_index' => $this->input('chunk_index', 0),
            'total_chunks' => $this->input('total_chunks', 0),
            'filename' => $this->input('filename', ''),
            'total_size' => intval($this->input('total_size', 0)),
            'chunk_md5' => $this->input('chunk_md5', ''),
            'file_md5' => $this->input('file_md5', ''),
        ];

        $result = $this->fileManager()->uploadChunk($parentId, $chunkInfo);

        Security::jsonOutput($result);
    }

    public function resolveUploadConflict()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $uploadId = $this->input('upload_id', '');
        $conflictResolution = $this->input('conflict_resolution', '');

        if (empty($uploadId) || !in_array($conflictResolution, ['overwrite', 'keep_both', 'cancel'])) {
            $this->error('参数不完整');
        }

        $result = $this->fileManager()->resolveUploadConflict($uploadId, $conflictResolution);

        Security::jsonOutput($result);
    }

    public function cancelUpload()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $uploadId = $this->input('upload_id', '');

        if (empty($uploadId)) {
            $this->error('参数不完整');
        }

        $result = $this->fileManager()->cancelUpload($uploadId);

        Security::jsonOutput($result);
    }

    public function getUploadedChunks()
    {
        $this->requireAuth();

        $uploadId = $this->input('upload_id', '');
        if (empty($uploadId)) {
            $this->error('upload_id 不能为空');
        }

        $chunks = $this->fileManager()->getUploadedChunks($uploadId);

        Security::jsonOutput(['success' => true, 'uploaded_chunks' => $chunks]);
    }

    public function cleanupExpiredUploadTasks()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $count = $this->fileManager()->cleanupExpiredUploadTasks();
        Security::jsonOutput(['success' => true, 'cleaned' => $count]);
    }
}
