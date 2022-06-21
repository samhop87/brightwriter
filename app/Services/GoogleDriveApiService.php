<?php

namespace App\Services;

use App\Interfaces\DriveApiInterface;
use App\Models\User;
use App\Models\UserProject;
use Google\Exception;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class GoogleDriveApiService implements DriveApiInterface
{
    private GoogleDriveConnectionService $driveConnectionService;

    public function __construct(GoogleDriveConnectionService $driveConnectionService)
    {
        $this->driveConnectionService = $driveConnectionService;
    }

    /**
     * @param $folder_id
     * @return Collection|null
     * @throws Exception
     */
    public function retrieveProject($folder_id): ?Collection
    {
        return $this->recursiveMapFolders($folder_id, collect([]));
    }

    /**
     * @param $folder_id
     * @return FileList
     * @throws Exception
     */
    public function listFilesInFolder($folder_id): FileList
    {
        $driveService = $this->driveConnectionService->setupService(User::DRIVE, Auth::user());
        $optParams = [
            'fields' => "*",
            'q' => "'".$folder_id."' in parents"
        ];

        // This gets us all main subfolders and text files of the top-level project folder.
        return $driveService->files->listFiles($optParams);
    }

    /**
     * Checks a user's drive for manual changes and updates projects list
     *
     * @return void
     * @throws Exception
     */
    public function refreshProjects()
    {
        // TODO: this needs refactoring so it can be used for folders and docs too.
        $user = Auth::user();
        $service = $this->driveConnectionService->setupService(User::DRIVE, $user);

        foreach ($user->projects as $project) {
            $item = $service->files->get($project->project_id, ["fields" => "*"])->explicitlyTrashed;
            if ($item) {
                $project->delete();
            }
        }
    }

    /**
     * This function will be used to 'lazy-load' the last project to try and make things snappier on login.
     * @return Collection|null
     * @throws Exception
     */
    public function getLastProject(): ?Collection
    {
        $project = UserProject::latest();
        return $this->retrieveProject($project->project_id);
    }

    /**
     * @param $folder_id
     * @param Collection|null $projectContainer
     * @return Collection|null
     * @throws Exception
     */
    public function recursiveMapFolders($folder_id, Collection $projectContainer = null): ?Collection
    {
        // This gets us all main subfolders and text files of the top-level project folder.
        $results = $this->listFilesInFolder($folder_id);

        foreach ($results->getFiles() as $item) {
            if ($item->getMimeType() === 'application/vnd.google-apps.document') {
                $projectContainer->push(collect([
                    'id' => $item->getId(),
                    'type' => $item->getMimeType(),
                    'internal_type' => 'doc',
                    'title' => $item->getName(),
                ]));
            } elseif ($item->getMimeType() === 'application/vnd.google-apps.folder') {
                // Map into next folder down
                $projectContainer->push(collect([
                    'id' => $item->getId(),
                    'title' => $item->getName(),
                    'type' => $item->getMimeType(),
                    'internal_type' => 'folder',
                    'content' => $this->recursiveMapFolders($item->getId(), collect([])),
                ]));
            }
        }

        return $projectContainer;
    }

    /**
     * @param null $folder_id
     * @param String $name
     * @return DriveFile
     * @throws Exception
     */
    public function createFolder(string $name, $folder_id = null): DriveFile
    {
        $service = $this->driveConnectionService->setupService(User::DRIVE, Auth::user());

        // Create a new drive file instance
        $folder = new \Google_Service_Drive_DriveFile();

        // Set drive file type to folder, and set name
        $folder->setMimeType('application/vnd.google-apps.folder');
        $folder->setName($name);

        // Set the parent of the folder, unless it is main project folder.
        if ($folder_id) {
            $folder->setParents([$folder_id]);
        }

        return $service->files->create($folder);
    }

    /**
     * @param $folder_id
     * @param $title
     * @return DriveFile
     * @throws Exception
     */
    public function createFile($folder_id = null, $title = null): DriveFile
    {
        $service = $this->driveConnectionService->setupService(User::DRIVE, Auth::user());

        $file = new \Google_Service_Drive_DriveFile();

        if ($title) {
            $file->setName($title);
        } else {
            $file->setName('Text Document');
        }
        $file->setMimeType('application/vnd.google-apps.document');

        if ($folder_id) {
            $file->setParents([$folder_id]);
        }

        $file = $service->files->create($file);

//        if ($folder_id) {
//            $this->retrieveProject()
//        }

        return $file;
    }

    /**
     * @param $file_id
     * @return mixed
     * @throws Exception
     */
    public function getFile($file_id)
    {
        $driveService = $this->driveConnectionService->setupService(User::DRIVE, Auth::user());
        return $driveService->files->export($file_id, 'text/html', ['alt' => 'media']);
    }

    /**
     * @throws Exception
     */
    public function updateDocFile($file_id, $file_text)
    {
        // TIME FOR A HACK!
        $fullmeta = '<meta content="text/html; charset=UTF-8" http-equiv="content-type">';
        $replacement = '<html><head><meta content="text/html; charset=UTF-8" http-equiv="content-type"></head><body style="background-color:#ffffff;padding:72pt 72pt 72pt 72pt;max-width:468pt">';
        $string = str_replace($fullmeta,$replacement,$file_text);

        $complete = $string . '</body></html>';

        $additionalParams = [
            'data' => $complete,
        ];

        $driveService = $this->driveConnectionService->setupService(User::DRIVE, Auth::user());

        // File's new metadata.
//        $file = new \Google_Service_Drive_DriveFile();
//        $file->setTitle($request->file_title);
//        $file->setDescription($request->file_description);

        $newFile = $driveService->files->update($file_id, new \Google_Service_Drive_DriveFile(), $additionalParams);
    }

    /**
     * @throws Exception
     */
    public function deleteFile($id) {
        $driveService = $this->driveConnectionService->setupService(User::DRIVE, Auth::user());
        $file = $driveService->files->delete($id);
        // TODO: then refresh the tree, or find a way of dynamically removing the element
        // TODO: dynamically add the element. Do all the actual updating in the background.
    }
}