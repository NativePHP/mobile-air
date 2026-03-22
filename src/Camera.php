<?php

namespace Native\Mobile;

use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Camera\VideoCancelled;
use Native\Mobile\Events\Camera\VideoRecorded;
use Native\Mobile\Events\Gallery\MediaSelected;

class Camera
{
    /**
     * Capture a photo using the device camera
     *
     * Opens the native camera app for photo capture. The captured photo will trigger
     * the PhotoTaken event with the file path.
     *
     * @param  array  $options  Capture options
     *
     * @see PhotoTaken
     * @see PhotoCancelled
     */
    public function getPhoto(array $options = []): PendingPhotoCapture
    {
        return new PendingPhotoCapture($options);
    }

    /**
     * Pick media from the device gallery
     *
     * Opens the native gallery picker for selecting images and/or videos. The selected media
     * will trigger the MediaSelected event with file information.
     *
     * @param  string  $media_type  Type of media: 'image', 'video', or 'all' (default: 'all')
     * @param  bool  $multiple  Allow multiple selection (default: false)
     * @param  int  $max_items  Maximum items when multiple=true (default: 10)
     *
     * @see MediaSelected
     */
    public function pickImages(string $media_type = 'all', bool $multiple = false, int $max_items = 10): PendingMediaPicker
    {
        return (new PendingMediaPicker)
            ->mediaType($media_type)
            ->multiple($multiple, $max_items);
    }

    /**
     * Record a video using the device camera
     *
     * Opens the native camera app for video recording. The recorded video will trigger
     * the VideoRecorded event with the file path.
     *
     * Available options:
     * - maxDuration: (int) Maximum recording duration in seconds (optional)
     *
     * Note: Quality and camera selection are controlled by the native camera app.
     *
     * @param  array  $options  Recording options
     *
     * @see VideoRecorded
     * @see VideoCancelled
     */
    public function recordVideo(array $options = []): PendingVideoRecorder
    {
        return new PendingVideoRecorder($options);
    }
}
