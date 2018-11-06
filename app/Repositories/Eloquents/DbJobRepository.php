<?php

namespace App\Repositories\Eloquents;

use App\Models\Job;
use App\Repositories\Interfaces\ApplicationRepository;
use App\Repositories\Interfaces\CompanyRepository;
use App\Repositories\Interfaces\JobRepository;
use App\Repositories\Interfaces\JobSkillRepository;
use App\Repositories\Interfaces\JobTypeRepository;
use App\Repositories\Interfaces\SkillRepository;
use App\Repositories\Interfaces\UserRepository;
use App\Repositories\Interfaces\JobCategoryRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;


class DbJobRepository extends DbBaseRepository implements JobRepository
{
    protected $model;
    protected $jobSkillRepository;
    protected $companyRepository;
    protected $skill;
    protected $jobType;
    protected $user;
    protected $jobCategory;
    protected $applicationRepository;
    private const FORMAT_DATE = 'Y-m-d';

    /**
     * @param Job $model
     *
     */
    function __construct(
        Job $model,
        JobSkillRepository $jobSkillRepository,
        CompanyRepository $companyRepository,
        SkillRepository $skillRepository,
        JobTypeRepository $jobTypeRepository,
        UserRepository $userRepository,
        JobCategoryRepository $jobCategoryRepository,
        ApplicationRepository $applicationRepository
    ) {
        $this->model = $model;
        $this->jobSkillRepository = $jobSkillRepository;
        $this->companyRepository = $companyRepository;
        $this->skill = $skillRepository;
        $this->jobType = $jobTypeRepository;
        $this->user = $userRepository;
        $this->jobCategory = $jobCategoryRepository;
        $this->applicationRepository = $applicationRepository;
    }

    public function getAll($per)
    {
        $jobs = $this->model::with('locationJobs', 'jobTypeJobs')->get();

        return $this->paginatorJob($this->getJobWithSkillName($jobs), $per);
    }

    public function paginatorJob($listJob, $per, $url = null)
    {
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $itemCollection = collect($listJob);
        $perPage = $per;
        $currentPageItems = $itemCollection->slice(($currentPage * $perPage) - $perPage, $perPage);
        $paginatedItems = new LengthAwarePaginator($currentPageItems, count($itemCollection), $perPage);
        if ($url) {
            $paginatedItems->withPath($url);
        }

        return $paginatedItems;
    }

    public function create($param)
    {
        return $this->baseCreate($param);
    }

    public function get($key, $value)
    {
        return $this->model::with('locationJobs', 'jobTypeJobs', 'applications', 'userJob')
            ->where($key, $value)->first();
    }

    public function update($data, $key, $value)
    {
        return $this->baseUpdate($data, $key, $value);
    }

    public function updateJobStatus(int $jobId)
    {
        $job = $this->model::findOrFail($jobId);
        $job->is_available = $job->is_available == true ? false : true;
        $job->save();
    }

    public function delete($key, $value)
    {
        return $this->baseDestroy($key, $value);
    }

    public function getAllJobByCompany(int $companyId, int $per)
    {
        $jobs = $this->model::where('user_id', $companyId)->with('locationJobs', 'jobTypeJobs')->get();

        return $this->getJobWithSkillName($jobs);
    }

    public function getJobWithSkillName($jobs)
    {
        $authenticatedUser = Auth::user();
        $isUserAuthenticated = Auth::check();
        $roleName = $isUserAuthenticated ? $authenticatedUser->userRole->name : config('app.guest_role');
        $jobsWithSkill = [];
        foreach ($jobs as $job) {
            $skillName = [];
            $skills = $this->jobSkillRepository->findAllByJobId($job->id);
            if ($skills) {
                foreach ($skills as $skill) {
                    $skillName[] = $skill->skillJobs->name;
                }
            }
            $companyUserInfo = $this->user->getSpecifiedColumn('id', $job->user_id, ['name', 'token']);
            $jobsWithSkill[] = [
                'job' => $job,
                'skills' => $skillName,
                'company_name' => $companyUserInfo->name,
                'company_logo' => $this->companyRepository->getSpecifiedColumn('user_id', $job->user_id,
                    ['logo_url'])->logo_url,
                'token' => $companyUserInfo->token,
                'role_name' => $roleName,
                'can_apply' => $isUserAuthenticated ? $this->applicationRepository->checkDuplicate($authenticatedUser->id,
                    $job->id) : true,
            ];
        }

        return array_reverse($jobsWithSkill);
    }

    public function getAllJob($key, $value, $per)
    {
        return $this->model::with('locationJobs', 'applications', 'userJob')->where($key, $value)->orderBy('id',
            'desc')->paginate($per);
    }

    public function getJobByUser($key, $value)
    {
        return $this->baseFindAllBy($key, $value);
    }

    public function searchJob($keyword, $location, $per, $url)
    {
        //tim kiem theo skill
        $skillSearch = $this->skill->searchSkillByName($keyword);
        $listJobId = [];
        $listJob = [];
        if ($skillSearch) {
            $jobSkills = $this->jobSkillRepository->findAllJobBySkill($skillSearch);
            foreach ($jobSkills as $jobSkill) {
                if (!in_array($jobSkill, $listJobId)) {
                    $listJobId[] = $jobSkill;
                }
            }

        }
        //tim kiem theo job_type
        $jobTypeSearch = $this->jobType->searchJobTypeByName($keyword);
        if ($jobTypeSearch) {
            foreach ($jobTypeSearch as $jt) {
                $jobs = $this->model->where('job_type_id', $jt->id)->get();
                foreach ($jobs as $job) {
                    if (!in_array($job->id, $listJobId)) {
                        $listJobId[] = $job->id;
                    }
                }

            }
        }
        //tim kiem theo company
        $companies = $this->user->searchCompanyByName($keyword);
        if ($companies) {
            foreach ($companies as $company) {
                $jobCompanies = $this->model->where('user_id', $company->id)->get();
                foreach ($jobCompanies as $jobCompany) {
                    if (!in_array($jobCompany->id, $listJobId)) {
                        $listJobId[] = $jobCompany->id;
                    }
                }
            }
        }
        //tim kiem theo tite job
        $jobTitles = $this->model->where('title', 'like', '%' . $keyword . '%')->get();
        if ($jobTitles) {
            foreach ($jobTitles as $jobTitle) {
                if (!in_array($jobTitle->id, $listJobId)) {
                    $listJobId[] = $jobTitle->id;
                }
            }
        }
        //tim kiem theo location
        if (isset($location) && !empty($listJobId)) {
            foreach ($listJobId as $ljd) {
                $activeJobs = $this->model->where(['id' => $ljd, 'location_id' => $location])->first();
                if ($activeJobs) {
                    if (!in_array($activeJobs->id, $listJob)) {
                        $listJob[] = $activeJobs->id;
                    }
                }
            }
        } elseif ($location) {
            $activeJobs = $this->model->where('location_id', $location)->get();
            if ($activeJobs) {
                foreach ($activeJobs as $activeJob) {
                    if (!in_array($activeJob->id, $listJob)) {
                        $listJob[] = $activeJob->id;
                    }
                }
            }
        } elseif (!empty($listJobId)) {
            $listJob = $listJobId;
        }

        return $this->getJobByDate($listJob, $per, $url);
    }

    private function getJobByDate($jobs, $per, $url)
    {
        $listJobs = [];
        if ($jobs) {
            foreach ($jobs as $job) {
                $job = $this->get('id', $job);
                if ($job) {
                    if ($this->compareDateJob($job->out_date)) {
                        $listJobs[] = $job;
                    }
                }
            }
        }

        return $this->paginatorJob($this->getJobWithSkillName($listJobs), $per, $url);
    }

    private function compareDateJob($outDate)
    {
        $dateCurrent = strtotime(date(self::FORMAT_DATE));
        $outDate = strtotime($outDate);
        if ($outDate >= $dateCurrent) {
            return true;
        }

        return false;
    }

    public function getJobByCategory($categoryIds)
    {
        $listJobs = [];
        if ($categoryIds) {
            $i = 0;
            foreach ($categoryIds as $categoryId) {
                $i++;
                $jobIds = $this->jobCategory->getJobIdByCategory($categoryId);
                if ($i == 1) {
                    foreach ($jobIds as $jobId) {
                        $listJobs[] = $jobId;
                    }
                } else {
                    $listJobs = $this->getJobByArray($listJobs, $jobIds);
                    foreach ($listJobs as $listJob) {
                        if (!in_array($listJob, $listJobs)) {
                            $listJobs[] = $listJob;
                        }
                    }
                }
            }
        } else {
            return null;
        }

        return $listJobs;
    }

    public function getJobBySalary($salary)
    {
        if ($salary) {
            if (array_key_exists($salary[0], config('app.ListSalary'))) {
                $listArray = [];
                $salaryConvert = explode('.', $salary[0]);
                if ($salaryConvert[0] === 'F') {
                    $jobsForSalaries = $this->model->where('salary_min', '>=', (int)$salaryConvert[1])->get(['id']);

                } else {
                    $jobsForSalaries = $this->model->whereBetween('salary_min',
                        [(int)$salaryConvert[0], (int)$salaryConvert[1]])->get(['id']);
                }
                foreach ($jobsForSalaries as $jobsForSalary) {
                    $listArray[] = $jobsForSalary->id;
                }

                return $listArray;
            }
        }

        return null;
    }

    public function getJobBySalaryCategory($salary, $categoryId, $per, $url)
    {
        $jobs = null;
        if ($salary && $categoryId) {
            $jobs = $this->getJobByArray($this->getJobBySalary($salary), $this->getJobByCategory($categoryId));
        } elseif ($salary) {
            $jobs = $this->getJobBySalary($salary);
        } elseif ($categoryId) {
            $jobs = $this->getJobByCategory($categoryId);
        } else {
            $jobs = $this->model->get(['id'])->toArray();
        }

        return $this->getJobByDate($jobs, $per, $url);
    }

    public function getJobByArray($arrayJobBefore, $arrayJobAfter)
    {
        if (!empty($arrayJobBefore) && !empty($arrayJobAfter)) {
            return array_intersect($arrayJobBefore, $arrayJobAfter);
        }

        return null;
    }

    public function getAllApplication(int $userId)
    {
        $applications = $this->applicationRepository->getAllAppliedJobByUser($userId);

        return $applications;
    }

    public function getLatestJobs(int $companyId)
    {
        return $this->model::where('user_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->take(config('app.record_number'))->get();
    }

    public function getAllAvailableJob(int $recordPerPage, $userIds)
    {
        $jobs = $this->getJobWithSkillName($this->model::where('is_available', config('app.job_open_status'))->whereIn('user_id', $userIds)->get());

        return $this->paginatorJob($jobs, $recordPerPage);
    }
}
