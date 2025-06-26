<?php

namespace App\\Service;

/**
 * Interface for converting speech audio to text.
 */
interface SpeechToTextServiceInterface
{
    /**
     * Transcribe the given audio file to text.
     *
     * @param string $audioPath Path to the audio file.
     * @return string|null The transcribed text or null on failure.
     */
    public function transcribe(string $audioPath): ?string;
}

