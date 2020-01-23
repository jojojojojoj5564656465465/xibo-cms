<?php
/**
 * Copyright (C) 2019 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - http://www.xibo.org.uk
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Xibo\XTR;
use Xibo\Factory\DisplayFactory;
use Xibo\Factory\MediaFactory;
use Xibo\Factory\ScheduleFactory;
use Xibo\Service\DateServiceInterface;
use Xibo\Service\ImageProcessingServiceInterface;

/**
 * Class ImageProcessingTask
 * @package Xibo\XTR
 */
class ImageProcessingTask implements TaskInterface
{
    use TaskTrait;

    /** @var DateServiceInterface */
    private $date;

    /** @var ImageProcessingServiceInterface */
    private $imageProcessingService;

    /** @var MediaFactory */
    private $mediaFactory;

    /** @var DisplayFactory */
    private $displayFactory;

    /** @var ScheduleFactory */
    private $scheduleFactory;

    /** @inheritdoc */
    public function setFactories($container)
    {
        $this->date = $container->get('dateService');
        $this->mediaFactory = $container->get('mediaFactory');
        $this->displayFactory = $container->get('displayFactory');
        $this->scheduleFactory = $container->get('scheduleFactory');
        $this->imageProcessingService = $container->get('imageProcessingService');
        return $this;
    }

    /** @inheritdoc */
    public function run()
    {
        $this->runMessage = '# ' . __('Image Processing') . PHP_EOL . PHP_EOL;

        // Long running task
        set_time_limit(0);

        $this->runImageProcessing();
    }

    /**
     *
     */
    private function runImageProcessing()
    {
        $images = $this->mediaFactory->query(null, ['released' => 0, 'allModules' => 1, 'imageProcessing' => 1]);

        $libraryLocation = $this->config->getSetting('LIBRARY_LOCATION');
        $resizeThreshold = $this->config->getSetting('DEFAULT_RESIZE_THRESHOLD');
        $count = 0;


        $eventsCached = [];
        $displaysCached = [];

        // Get list of Images
        foreach ($images as $media) {

            $filePath = $libraryLocation . $media->storedAs;
            list($imgWidth, $imgHeight) = @getimagesize($filePath);

            // Orientation of the image
            if ($imgWidth > $imgHeight) { // 'landscape';
                $this->imageProcessingService->resizeImage($filePath, $resizeThreshold, null);
            } else { // 'portrait';
                $this->imageProcessingService->resizeImage($filePath, null, $resizeThreshold);
            }

            // Clears file status cache
            clearstatcache(true, $filePath);

            $count++;

            // Release image and save
            $media->release(md5_file($filePath), filesize($filePath));
            $this->store->commitIfNecessary();


            // Get events using the media
            $events = $this->scheduleFactory->query(null, ['mediaId' => $media->mediaId]);

            foreach ($events as $event) {
                /** @var \Xibo\Entity\Schedule $event */
                if (in_array($event->eventId, $eventsCached))
                    continue;

                // Cache event
                $eventsCached[] = $event->eventId;

                $event->load();
                $displayGroups = $event->displayGroups;

                foreach ($displayGroups as $displayGroup) {
                    $displays = $this->displayFactory->query(null, ['displayGroupId' => $displayGroup->displayGroupId]);

                    foreach ($displays as $display) {
                        if (in_array($display->displayId, $displaysCached))
                            continue;

                        // Cache display
                        $displaysCached[] = $display->displayId;
                    }
                }
            }

        }

        // Finally notify displays
        if (count($displaysCached) > 0) {
            foreach ($displaysCached as $displayId) {
                $display = $this->displayFactory->getById($displayId);
                $display->notify();
            }
        }

        $this->appendRunMessage('Released and modified image count. ' . $count);

    }
}