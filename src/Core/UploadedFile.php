<?php

namespace SecurityScanner\Core;

class UploadedFile
{
    private string $tmpName;
    private string $name;
    private string $type;
    private int $size;
    private int $error;

    public function __construct(string $tmpName, string $name, string $type, int $size, int $error)
    {
        $this->tmpName = $tmpName;
        $this->name = $name;
        $this->type = $type;
        $this->size = $size;
        $this->error = $error;
    }

    /**
     * Get temporary file path
     */
    public function getTmpName(): string
    {
        return $this->tmpName;
    }

    /**
     * Get original filename
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get file MIME type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get file size in bytes
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Get upload error code
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * Check if upload was successful
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    /**
     * Get file extension
     */
    public function getExtension(): string
    {
        return pathinfo($this->name, PATHINFO_EXTENSION);
    }

    /**
     * Get filename without extension
     */
    public function getBasename(): string
    {
        return pathinfo($this->name, PATHINFO_FILENAME);
    }

    /**
     * Move uploaded file to destination
     */
    public function move(string $destination): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        return move_uploaded_file($this->tmpName, $destination);
    }

    /**
     * Get file contents
     */
    public function getContents(): string
    {
        if (!$this->isValid()) {
            return '';
        }

        return file_get_contents($this->tmpName) ?: '';
    }
}