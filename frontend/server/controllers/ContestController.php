<?php

/**
 * ContestController
 *
 */
class ContestController extends Controller {
    const SHOW_INTRO = true;
    const MAX_CONTEST_LENGTH_SECONDS = 2678400; // 31 days

    /**
     * Returns a list of contests
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiList(Request $r) {
        // Check who is visiting, but a not logged user can still view
        // the list of contests
        try {
            self::authenticateRequest($r);
        } catch (UnauthorizedException $e) {
            // Do nothing.
        }

        try {
            $contests = array();

            if ($r['current_user_id'] === null) {
                // Get all public contests
                Cache::getFromCacheOrSet(
                    Cache::CONTESTS_LIST_PUBLIC,
                    '',
                    $r,
                    function (Request $r) {
                            return ContestsDAO::getAllPublicContests();
                    },
                    $contests
                );
            } elseif (Authorization::IsSystemAdmin($r['current_user_id'])) {
                // Get all contests
                Cache::getFromCacheOrSet(
                    Cache::CONTESTS_LIST_SYSTEM_ADMIN,
                    '',
                    $r,
                    function (Request $r) {
                            return ContestsDAO::getAllContests();
                    },
                    $contests
                );
            } else {
                // Get all public+private contests
                $contests = ContestsDAO::getAllContestsForUser($r['current_user_id']);
            }
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        // Filter returned values by these columns
        $relevantColumns = array(
            'contest_id',
            'title',
            'description',
            'start_time',
            'finish_time',
            'public',
            'alias',
            'director_id',
            'window_length',
            'recommended',
            );

        $addedContests = array();
        foreach ($contests as $c) {
            $contestInfo = $c->asFilteredArray($relevantColumns);

            $contestInfo['duration'] = (is_null($c->getWindowLength()) ?
                                $c->getFinishTime() - $c->getStartTime() : ($c->getWindowLength() * 60));

            $addedContests[] = $contestInfo;
        }

        return array(
            'number_of_results' => sizeof($addedContests),
            'results' => $addedContests
        );
    }

    /**
     * Returls a list of contests where current user is the director
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiMyList(Request $r) {
        self::authenticateRequest($r);

        // Create array of relevant columns
        $relevant_columns = array('title', 'alias', 'start_time', 'finish_time', 'public', 'scoreboard_url', 'scoreboard_url_admin');
        $contests = null;
        try {
            $contests = ContestsDAO::getAll(null, null, 'contest_id', 'DESC');

            // If current user is not sys admin, then we need to filter out the contests where
            // the current user is not contest admin
            if (!Authorization::IsSystemAdmin($r['current_user_id'])) {
                $contests_all = $contests;
                $contests = array();

                foreach ($contests_all as $c) {
                    if (Authorization::IsContestAdmin($r['current_user_id'], $c)) {
                        $contests[] = $c;
                    }
                }
            }
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        $addedContests = array();
        foreach ($contests as $c) {
            $c->toUnixTime();
            $contestInfo = $c->asFilteredArray($relevant_columns);
            $addedContests[] = $contestInfo;
        }

        $response['results'] = $addedContests;
        $response['status'] = 'ok';
        return $response;
    }

    /**
     * Checks if user can access contests: If the contest is private then the user
     * must be added to the contest (an entry ContestsUsers must exists) OR the user
     * should be a Contest Admin.
     *
     * Expects $r["contest"] to contain the contest to check against.
     *
     * In case of access check failed, an exception is thrown.
     *
     * @param Request $r
     * @throws ApiException
     * @throws InvalidDatabaseOperationException
     * @throws ForbiddenAccessException
     */
    public static function canAccessContest(Request $r) {
        if (!isset($r['contest']) || is_null($r['contest'])) {
            throw new NotFoundException('contestNotFound');
        }

        if (!($r['contest'] instanceof Contests)) {
            throw new InvalidParameterException('contest must be an instance of ContestVO');
        }

        if ($r['contest']->public != 1) {
            try {
                if (is_null(ContestsUsersDAO::getByPK($r['current_user_id'], $r['contest']->getContestId()))
                        && !Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
                    throw new ForbiddenAccessException('userNotAllowed');
                }
            } catch (ApiException $e) {
                // Propagate exception
                throw $e;
            } catch (Exception $e) {
                // Operation failed in the data layer
                throw new InvalidDatabaseOperationException($e);
            }
        } else {
            if ($r['contest']->contestant_must_register == '1') {
                if (!Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
                    $req = ContestUserRequestDAO::getByPK($r['current_user_id'], $r['contest']->contest_id);

                    if (is_null($req) || ($req->accepted === '0')) {
                        throw new ForbiddenAccessException('contestNotRegistered');
                    }
                }
            }
        }
    }

    /**
     * Validate the basics of a contest request.
     *
     */
    private static function validateBasicDetails(Request $r) {
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');
        // If the contest is private, verify that our user is invited
        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($r['contest'])) {
            throw new NotFoundException('contestNotFound');
        }
    }

    /**
     * Validate if a contestant has explicit access to a contest.
     *
     * @param Request $r
     */
    public static function isInvitedToContest(Request $r) {
        if (is_null($r['contest']) || is_null($r['current_user_id'])) {
            return false;
        }
        return $r['contest']->public == 1 ||
            !is_null(ContestsUsersDAO::getByPK(
                $r['current_user_id'],
                $r['contest']->getContestId()
            ));
    }

    /**
     * Show the contest intro unless you are admin, or you
     * already started this contest.
     */
    public static function showContestIntro(Request $r) {
        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            throw new NotFoundException('contestNotFound');
        }
        if (is_null($r['contest'])) {
            throw new NotFoundException('contestNotFound');
        }

        try {
            // Half-authenticate, in case there is no session in place.
            $session = SessionController::apiCurrentSession($r);
            if ($session['valid'] && !is_null($session['user'])) {
                $r['current_user'] = $session['user'];
                $r['current_user_id'] = $session['user']->user_id;
            } else {
                // No session, show the intro (if public), so that they can login.
                return $r['contest']->public ? ContestController::SHOW_INTRO : !ContestController::SHOW_INTRO;
            }
            self::canAccessContest($r);
        } catch (Exception $e) {
            // Could not access contest. Private contests must not be leaked, so
            // unless they were manually added beforehand, show them a 404 error.
            if (!ContestController::isInvitedToContest($r)) {
                throw $e;
            }
            self::$log->error('Exception while trying to verify access: ' . $e);
            return ContestController::SHOW_INTRO;
        }

        $cs = SessionController::apiCurrentSession();

        // You already started the contest.
        $contestOpened = ContestsUsersDAO::getByPK(
            $r['current_user_id'],
            $r['contest']->getContestId()
        );
        if (!is_null($contestOpened) &&
            $contestOpened->access_time != '0000-00-00 00:00:00') {
            self::$log->debug('Not intro because you already started the contest');
            return !ContestController::SHOW_INTRO;
        }

        return ContestController::SHOW_INTRO;
    }

    /**
     * Validate request of a details contest
     *
     * @param Request $r
     * @throws InvalidDatabaseOperationException
     * @throws NotFoundException
     * @throws Exception
     * @throws ForbiddenAccessException
     * @throws PreconditionFailedException
     */
    private static function validateDetails(Request $r) {
        self::validateBasicDetails($r);

        $r['contest_admin'] = false;

        // If the contest has not started, user should not see it, unless it is admin or has a token.
        if (is_null($r['token'])) {
            // Crack the request to get the current user
            self::authenticateRequest($r);
            self::canAccessContest($r);

            $r['contest_admin'] = Authorization::IsContestAdmin($r['current_user_id'], $r['contest']);
            if (!ContestsDAO::hasStarted($r['contest']) && !$r['contest_admin']) {
                $exception = new PreconditionFailedException('contestNotStarted');
                $exception->addCustomMessageToArray('start_time', strtotime($r['contest']->getStartTime()));

                throw $exception;
            }
        } else {
            if ($r['token'] === $r['contest']->getScoreboardUrlAdmin()) {
                $r['contest_admin'] = true;
            } elseif ($r['token'] !== $r['contest']->getScoreboardUrl()) {
                throw new ForbiddenAccessException('invalidScoreboardUrl');
            }
        }
    }

    public static function apiPublicDetails(Request $r) {
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        $result = array();

        // If the contest is private, verify that our user is invited
        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($r['contest'])) {
            throw new NotFoundException('contestNotFound');
        }

        // Create array of relevant columns
        $relevant_columns = array('title', 'description', 'start_time', 'finish_time', 'window_length', 'alias', 'scoreboard', 'points_decay_factor', 'partial_score', 'submissions_gap', 'feedback', 'penalty', 'time_start', 'penalty_type', 'penalty_calc_policy', 'public', 'show_scoreboard_after', 'contestant_must_register');

        // Initialize response to be the contest information
        $result = $r['contest']->asFilteredArray($relevant_columns);

        $current_ses = SessionController::getCurrentSession($r);
        $result['contestant_must_register'] = ($result['contestant_must_register'] == '1');

        if ($current_ses['valid'] && $result['contestant_must_register']) {
            $registration = ContestUserRequestDAO::getByPK($current_ses['id'], $r['contest']->contest_id);

            $result['user_registration_requested'] = !is_null($registration);

            if (is_null($registration)) {
                $result['user_registration_accepted'] = false;
                $result['user_registration_answered'] = false;
            } else {
                $result['user_registration_answered'] = !is_null($registration->getAccepted());
                $result['user_registration_accepted'] = $registration->getAccepted() == '1';
            }
        }

        $result['start_time'] = strtotime($result['start_time']);
        $result['finish_time'] = strtotime($result['finish_time']);

        $result['status'] = 'ok';

        return $result;
    }

    public static function apiRegisterForContest(Request $r) {
        self::validateBasicDetails($r);

        $current_user = AuthTokensDAO::getUserByToken($r['auth_token']);

        $contest_req = new ContestUserRequest();
        $contest_req->setUserId($current_user->getUserId());
        $contest_req->setContestId($r['contest']->getContestId());
        $contest_req->setRequestTime(gmdate('Y-m-d H:i:s'));

        try {
            ContestUserRequestDAO::save($contest_req);
        } catch (Exception $e) {
            self::$log->error('Failed to create new ContestUserRequest: ' . $e->getMessage());
            throw new InvalidDatabaseOperationException($e);
        }

        return array('status' => 'ok');
    }

    /**
     * Joins a contest - explicitly adds a user to a contest.
     *
     * @param Request $r
     */
    public static function apiOpen(Request $r) {
        self::validateDetails($r);
        ContestsUsersDAO::CheckAndSaveFirstTimeAccess(
            $r['current_user_id'],
            $r['contest']->contest_id,
            true
        );
        self::$log->info("User '{$r['current_user']->username}' joined contest '{$r['contest']->alias}'");
        return array('status' => 'ok');
    }

    /**
     * Returns details of a Contest. This is shared between apiDetails and
     * apiAdminDetails.
     *
     * @param Request $r
     * @param $result
     */
    private static function getCachedDetails(Request $r, &$result) {
        Cache::getFromCacheOrSet(Cache::CONTEST_INFO, $r['contest_alias'], $r, function (Request $r) {
            // Create array of relevant columns
            $relevant_columns = array(
                'title',
                'description',
                'start_time',
                'finish_time',
                'window_length',
                'alias',
                'scoreboard',
                'points_decay_factor',
                'partial_score',
                'submissions_gap',
                'feedback',
                'penalty',
                'time_start',
                'penalty_type',
                'penalty_calc_policy',
                'public',
                'show_scoreboard_after',
                'contestant_must_register',
                'languages');

            // Initialize response to be the contest information
            $result = $r['contest']->asFilteredArray($relevant_columns);

            $result['start_time'] = strtotime($result['start_time']);
            $result['finish_time'] = strtotime($result['finish_time']);

            try {
                $result['director'] = UsersDAO::getByPK($r['contest']->director_id)->username;
            } catch (Exception $e) {
                // Operation failed in the data layer
                throw new InvalidDatabaseOperationException($e);
            }

            // Get problems of the contest
            $key_problemsInContest = new ContestProblems(
                array(
                        'contest_id' => $r['contest']->getContestId()
                    )
            );

            try {
                $problemsInContest = ContestProblemsDAO::search($key_problemsInContest, 'order');
            } catch (Exception $e) {
                // Operation failed in the data layer
                throw new InvalidDatabaseOperationException($e);
            }

            // Add info of each problem to the contest
                    $problemsResponseArray = array();

            // Set of columns that we want to show through this API. Doesn't include the SOURCE
                    $relevant_columns = array('title', 'alias', 'validator', 'time_limit',
                    'overall_wall_time_limit', 'extra_wall_time', 'memory_limit',
                    'visits', 'submissions', 'accepted', 'dificulty', 'order',
                    'languages');
                    $letter = 0;

                    foreach ($problemsInContest as $problemkey) {
                        try {
                            // Get the data of the problem
                            $temp_problem = ProblemsDAO::getByPK($problemkey->getProblemId());
                        } catch (Exception $e) {
                            // Operation failed in the data layer
                            throw new InvalidDatabaseOperationException($e);
                        }

                                // Add the 'points' value that is stored in the ContestProblem relationship
                                $temp_array = $temp_problem->asFilteredArray($relevant_columns);
                                $temp_array['points'] = $problemkey->getPoints();
                                $temp_array['letter'] = ContestController::columnName($letter++);
                        if (!empty($result['languages'])) {
                            $temp_array['languages'] = join(',', array_intersect(
                                explode(',', $result['languages']),
                                explode(',', $temp_array['languages'])
                            ));
                        }

                                // Save our array into the response
                                array_push($problemsResponseArray, $temp_array);
                    }

            // Add problems to response
                    $result['problems'] = $problemsResponseArray;

                    return $result;
        }, $result, APC_USER_CACHE_CONTEST_INFO_TIMEOUT);
    }

    /**
     * Returns details of a Contest. Requesting the details of a contest will
     * not start the current user into that contest. In order to participate
     * in the contest, ContestController::apiOpen() must be used.
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiDetails(Request $r) {
        self::validateDetails($r);

        $result = array();
        self::getCachedDetails($r, $result);

        if (is_null($r['token'])) {
            // Adding timer info separately as it depends on the current user and we don't
            // want this to get generally cached for everybody
            // Save the time of the first access
            try {
                $contest_user = ContestsUsersDAO::CheckAndSaveFirstTimeAccess(
                    $r['current_user_id'],
                    $r['contest']->contest_id
                );
            } catch (ApiException $e) {
                throw $e;
            } catch (Exception $e) {
                // Operation failed in the data layer
                throw new InvalidDatabaseOperationException($e);
            }

            // Add time left to response
            if ($r['contest']->getWindowLength() === null) {
                $result['submission_deadline'] = strtotime($r['contest']->getFinishTime());
            } else {
                $result['submission_deadline'] = min(
                    strtotime($r['contest']->getFinishTime()),
                    strtotime($contest_user->access_time) + $r['contest']->getWindowLength() * 60
                );
            }
            $result['admin'] = Authorization::IsContestAdmin($r['current_user_id'], $r['contest']);

            // Log the operation.
            ContestAccessLogDAO::save(new ContestAccessLog(array(
                'user_id' => $r['current_user_id'],
                'contest_id' => $r['contest']->contest_id,
                'ip' => ip2long($_SERVER['REMOTE_ADDR']),
            )));
        }

        $result['status'] = 'ok';
        return $result;
    }

    /**
     * Returns details of a Contest, for administrators. This differs from
     * apiDetails in the sense that it does not attempt to calculate the
     * remaining time from the contest, or register the opened time.
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiAdminDetails(Request $r) {
        self::validateDetails($r);

        if (!Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
            throw new ForbiddenAccessException();
        }

        $result = array();
        self::getCachedDetails($r, $result);

        $result['status'] = 'ok';
        $result['admin'] = true;
        return $result;
    }

    /**
     * Returns a report with all user activity for a contest.
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiActivityReport(Request $r) {
        self::validateDetails($r);

        if (!$r['contest_admin']) {
            throw new ForbiddenAccessException();
        }

        $accesses = ContestAccessLogDAO::GetAccessForContest($r['contest']);
        $submissions = SubmissionLogDAO::GetSubmissionsForContest($r['contest']);

        // Merge both logs.
        $result['events'] = array();
        $lenAccesses = count($accesses);
        $lenSubmissions = count($submissions);
        $iAccesses = 0;
        $iSubmissions = 0;

        while ($iAccesses < $lenAccesses && $iSubmissions < $lenSubmissions) {
            if ($accesses[$iAccesses]['time'] < $submissions[$iSubmissions]['time']) {
                array_push($result['events'], ContestController::processAccess(
                    $accesses[$iAccesses++]
                ));
            } else {
                array_push($result['events'], ContestController::processSubmission(
                    $submissions[$iSubmissions++]
                ));
            }
        }

        while ($iAccesses < $lenAccesses) {
            array_push($result['events'], ContestController::processAccess(
                $accesses[$iAccesses++]
            ));
        }

        while ($iSubmissions < $lenSubmissions) {
            array_push($result['events'], ContestController::processSubmission(
                $submissions[$iSubmissions++]
            ));
        }

        // Anonimize data.
        $ipMapping = array();
        foreach ($result['events'] as &$entry) {
            if (!array_key_exists($entry['ip'], $ipMapping)) {
                $ipMapping[$entry['ip']] = count($ipMapping);
            }
            $entry['ip'] = $ipMapping[$entry['ip']];
        }

        $result['status'] = 'ok';
        return $result;
    }

    private static function processAccess(&$access) {
        return array(
            'username' => $access['username'],
            'time' => (int)$access['time'],
            'ip' => (int)$access['ip'],
            'event' => array(
                'name' => 'open',
            ),
        );
    }

    private static function processSubmission(&$submission) {
        return array(
            'username' => $submission['username'],
            'time' => (int)$submission['time'],
            'ip' => (int)$submission['ip'],
            'event' => array(
                'name' => 'submit',
                'problem' => $submission['alias'],
            ),
        );
    }

    /**
     * Returns a "column name" for the $idx (think Excel column names).
     */
    private static function columnName($idx) {
        $name = chr(ord('A') + $idx % 26);
        while ($idx >= 26) {
            $idx /= 26;
            $idx--;
            $name = chr(ord('A') + $idx % 26) . $name;
        }
        return $name;
    }

    /**
     * Creates a new contest
     *
     * @param Request $r
     * @return array
     * @throws DuplicatedEntryInDatabaseException
     * @throws InvalidDatabaseOperationException
     */
    public static function apiCreate(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Authenticate user
        self::authenticateRequest($r);

        // Validate request
        self::validateCreateOrUpdate($r);

        // Create and populate a new Contests object
        $contest = new Contests();

        $contest->setPublic($r['public']);
        $contest->setTitle($r['title']);
        $contest->setDescription($r['description']);
        $contest->setStartTime(gmdate('Y-m-d H:i:s', $r['start_time']));
        $contest->setFinishTime(gmdate('Y-m-d H:i:s', $r['finish_time']));
        $contest->setWindowLength($r['window_length'] === 'NULL' ? null : $r['window_length']);
        $contest->setDirectorId($r['current_user_id']);
        $contest->setRerunId(0); // NYI
        $contest->setAlias($r['alias']);
        $contest->setScoreboard($r['scoreboard']);
        $contest->setPointsDecayFactor($r['points_decay_factor']);
        $contest->setPartialScore(is_null($r['partial_score']) ? '1' : $r['partial_score']);
        $contest->setSubmissionsGap($r['submissions_gap']);
        $contest->setFeedback($r['feedback']);
        $contest->setPenalty(max(0, intval($r['penalty'])));
        $contest->penalty_type = $r['penalty_type'];
        $contest->setPenaltyCalcPolicy(is_null($r['penalty_calc_policy']) ? 'sum' : $r['penalty_calc_policy']);
        $contest->setLanguages(empty($r['languages']) ? null : $r['languages']);
        $contest->setScoreboardUrl(self::randomString(30));
        $contest->setScoreboardUrlAdmin(self::randomString(30));
        $contest->setInterview($r['interview']);
        $contest->setContestantMustRegister($r['contestant_must_register']);

        if (!is_null($r['show_scoreboard_after'])) {
            $contest->setShowScoreboardAfter($r['show_scoreboard_after']);
        } else {
            $contest->setShowScoreboardAfter('1');
        }

        if ($r['public'] == 1) {
            self::validateContestCanBePublic($contest);
        }

        // Push changes
        try {
            // Begin a new transaction
            ContestsDAO::transBegin();

            // Save the contest object with data sent by user to the database
            ContestsDAO::save($contest);

            // If the contest is private, add the list of allowed users
            if ($r['public'] != 1 && $r['hasPrivateUsers']) {
                foreach ($r['private_users_list'] as $userkey) {
                    // Create a temp DAO for the relationship
                    $temp_user_contest = new ContestsUsers(array(
                                'contest_id' => $contest->getContestId(),
                                'user_id' => $userkey,
                                'access_time' => '0000-00-00 00:00:00',
                                'score' => 0,
                                'time' => 0
                            ));

                    // Save the relationship in the DB
                    ContestsUsersDAO::save($temp_user_contest);
                }
            }

            if (!is_null($r['problems'])) {
                foreach ($r['problems'] as $problem) {
                    $contest_problem = new ContestProblems(array(
                                'contest_id' => $contest->getContestId(),
                                'problem_id' => $problem['id'],
                                'points' => $problem['points']
                            ));

                    ContestProblemsDAO::save($contest_problem);
                }
            }

            // End transaction transaction
            ContestsDAO::transEnd();
        } catch (Exception $e) {
            // Operation failed in the data layer, rollback transaction
            ContestsDAO::transRollback();

            // Alias may be duplicated, 1062 error indicates that
            if (strpos($e->getMessage(), '1062') !== false) {
                throw new DuplicatedEntryInDatabaseException('aliasInUse', $e);
            } else {
                throw new InvalidDatabaseOperationException($e);
            }
        }

        // Expire contes-list cache
        Cache::deleteFromCache(Cache::CONTESTS_LIST_PUBLIC);
        Cache::deleteFromCache(Cache::CONTESTS_LIST_SYSTEM_ADMIN);

        self::$log->info('New Contest Created: ' . $r['alias']);
        return array('status' => 'ok');
    }

    /**
     * Validates that Request contains expected data to create or update a contest
     * In case of update, everything is optional except the contest_alias
     * In case of error, this function throws.
     *
     * @param Request $r
     * @throws InvalidParameterException
     */
    private static function validateCreateOrUpdate(Request $r, $is_update = false) {
        // Is the parameter required?
        $is_required = true;

        if ($is_update === true) {
            // In case of Update API, required parameters for Create API are not required
            $is_required = false;

            try {
                $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
            } catch (Exception $e) {
                throw new InvalidDatabaseOperationException($e);
            }

            if (is_null($r['contest_alias'])) {
                throw new NotFoundException('contestNotFound');
            }

            if (!Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
                throw new ForbiddenAccessException();
            }
        }

        Validators::isStringNonEmpty($r['title'], 'title', $is_required);
        Validators::isStringNonEmpty($r['description'], 'description', $is_required);

        Validators::isNumber($r['start_time'], 'start_time', $is_required);
        Validators::isNumber($r['finish_time'], 'finish_time', $is_required);

        // Get the actual start and finish time of the contest, considering that
        // in case of update, parameters can be optional
        $start_time = !is_null($r['start_time']) ? $r['start_time'] : strtotime($r['contest']->getStartTime());
        $finish_time = !is_null($r['finish_time']) ? $r['finish_time'] : strtotime($r['contest']->getFinishTime());

        // Validate start & finish time
        if ($start_time > $finish_time) {
            throw new InvalidParameterException('contestNewInvalidStartTime');
        }

        // Calculate the actual contest length
        $contest_length = $finish_time - $start_time;

        // Validate max contest length
        $is_interview = (!is_null($r['interview']) && ($r['interview'] == 1 || $r['interview']));
        if (!$is_interview && $contest_length > ContestController::MAX_CONTEST_LENGTH_SECONDS) {
            throw new InvalidParameterException('contestLengthTooLong');
        }

        // Window_length is optional
        if (!is_null($r['window_length']) && $r['window_length'] !== 'NULL') {
            Validators::isNumberInRange(
                $r['window_length'],
                'window_length',
                0,
                floor($contest_length) / 60,
                false
            );
        }

        Validators::isInEnum($r['public'], 'public', array('0', '1'), $is_required);
        Validators::isValidAlias($r['alias'], 'alias', $is_required);
        Validators::isNumberInRange($r['scoreboard'], 'scoreboard', 0, 100, $is_required);
        Validators::isNumberInRange($r['points_decay_factor'], 'points_decay_factor', 0, 1, $is_required);
        Validators::isInEnum($r['partial_score'], 'partial_score', array('0', '1'), false);
        Validators::isNumberInRange($r['submissions_gap'], 'submissions_gap', 0, $contest_length, $is_required);

        Validators::isInEnum($r['feedback'], 'feedback', array('no', 'yes', 'partial'), $is_required);
        Validators::isInEnum($r['penalty_type'], 'penalty_type', array('contest_start', 'problem_open', 'runtime', 'none'), $is_required);
        Validators::isInEnum($r['penalty_calc_policy'], 'penalty_calc_policy', array('sum', 'max'), false);

        // Check that the users passed through the private_users parameter are valid
        if (!is_null($r['public']) && $r['public'] != 1 && !is_null($r['private_users'])) {
            // Validate that the request is well-formed
            $r['private_users_list'] = json_decode($r['private_users']);
            if (is_null($r['private_users_list'])) {
                throw new InvalidParameterException('parameterInvalid', 'private_users');
            }

            // Validate that all users exists in the DB
            foreach ($r['private_users_list'] as $userkey) {
                if (is_null(UsersDAO::getByPK($userkey))) {
                    throw new InvalidParameterException('parameterNotFound', 'private_users');
                }
            }

            // Turn on flag to add private users later
            $r['hasPrivateUsers'] = true;
        }

        // Problems is optional
        if (!is_null($r['problems'])) {
            $r['problems'] = array();

            foreach (json_decode($r['problems']) as $problem) {
                $p = ProblemsDAO::getByAlias($problem->problem);
                array_push($r['problems'], array(
                    'id' => $p->getProblemId(),
                    'alias' => $problem->problem,
                    'points' => $problem->points
                ));
            }
        }

        // Show scoreboard is always optional
        Validators::isInEnum($r['show_scoreboard_after'], 'show_scoreboard_after', array('0', '1'), false);

        if ($is_update) {
            // Prevent date changes if a contest already has runs
            if (!is_null($r['start_time']) && $r['start_time'] != strtotime($r['contest']->start_time)) {
                $runCount = 0;

                try {
                    $runCount = RunsDAO::CountTotalRunsOfContest($r['contest']->contest_id);
                } catch (Exception $e) {
                    throw new InvalidDatabaseOperationException($e);
                }

                if ($runCount > 0) {
                    throw new InvalidParameterException('contestUpdateAlreadyHasRuns');
                }
            }
        }
    }

    /**
     * Gets the problems from a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiProblems(Request $r) {
        // Authenticate user
        self::authenticateRequest($r);

        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        // Only director is allowed to create problems in contest
        try {
            $contest = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($contest)) {
            throw new InvalidParameterException('parameterNotFound', 'contest_alias');
        }

        // Only contest admin is allowed to view details through this API
        if (!Authorization::IsContestAdmin($r['current_user_id'], $contest)) {
            throw new ForbiddenAccessException('cannotAddProb');
        }

        try {
            $problems = ContestProblemsDAO::GetContestProblems(
                $contest->contest_id
            );
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        return array('status' => 'ok', 'problems' => $problems);
    }

    /**
     * Adds a problem to a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiAddProblem(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Authenticate user
        self::authenticateRequest($r);

        // Validate the request and get the problem and the contest in an array
        $params = self::validateAddToContestRequest($r);

        if (ContestProblemsDAO::CountContestProblems($params['contest']->contest_id)
                >= MAX_PROBLEMS_IN_CONTEST) {
            throw new PreconditionFailedException('contestAddproblemTooManyProblems');
        }

        try {
            $relationship = new ContestProblems(array(
                        'contest_id' => $params['contest']->getContestId(),
                        'problem_id' => $params['problem']->getProblemId(),
                        'points' => $r['points'],
                        'order' => is_null($r['order_in_contest']) ?
                                1 : $r['order_in_contest']));

            ContestProblemsDAO::save($relationship);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        // Invalidar cache
        Cache::deleteFromCache(Cache::CONTEST_INFO, $r['contest_alias']);
        Scoreboard::InvalidateScoreboardCache($params['contest']->contest_id);

        return array('status' => 'ok');
    }

    /**
     * Validates the request for AddToContest and returns an array with
     * the problem and contest DAOs
     *
     * @throws InvalidDatabaseOperationException
     * @throws InvalidParameterException
     * @throws ForbiddenAccessException
     */
    private static function validateAddToContestRequest(Request $r) {
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        // Only director is allowed to create problems in contest
        try {
            $contest = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($contest)) {
            throw new InvalidParameterException('parameterNotFound', 'contest_alias');
        }

        // Only contest admin is allowed to create problems in contest
        if (!Authorization::IsContestAdmin($r['current_user_id'], $contest)) {
            throw new ForbiddenAccessException('cannotAddProb');
        }

        Validators::isStringNonEmpty($r['problem_alias'], 'problem_alias');

        try {
            $problem = ProblemsDAO::getByAlias($r['problem_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($problem)) {
            throw new InvalidParameterException('parameterNotFound', 'problem_alias');
        }

        if ($problem->getPublic() == '0' && !Authorization::CanEditProblem($r['current_user_id'], $problem)) {
            throw new ForbiddenAccessException('problemIsPrivate');
        }

        Validators::isNumberInRange($r['points'], 'points', 0, INF);
        Validators::isNumberInRange($r['order_in_contest'], 'order_in_contest', 0, INF, false);

        return array(
            'contest' => $contest,
            'problem' => $problem);
    }

    /**
     * Removes a problem from a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiRemoveProblem(Request $r) {
        // Authenticate user
        self::authenticateRequest($r);

        // Validate the request and get the problem and the contest in an array
        $params = self::validateRemoveFromContestRequest($r);

        try {
            $relationship = new ContestProblems(array(
                'contest_id' => $params['contest']->contest_id,
                'problem_id' => $params['problem']->problem_id
            ));

            ContestProblemsDAO::delete($relationship);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        // Invalidar cache
        Cache::deleteFromCache(Cache::CONTEST_INFO, $r['contest_alias']);
        Scoreboard::InvalidateScoreboardCache($params['contest']->contest_id);

        return array('status' => 'ok');
    }

    /**
     * Validates the request for RemoveFromContest and returns an array with
     * the problem and contest DAOs
     *
     * @throws InvalidDatabaseOperationException
     * @throws InvalidParameterException
     * @throws ForbiddenAccessException
     */
    private static function validateRemoveFromContestRequest(Request $r) {
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        // Only director is allowed to create problems in contest
        try {
            $contest = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($contest)) {
            throw new InvalidParameterException('parameterNotFound', 'problem_alias');
        }

        // Only contest admin is allowed to create problems in contest
        if (!Authorization::IsContestAdmin($r['current_user_id'], $contest)) {
            throw new ForbiddenAccessException('cannotAddProb');
        }

        Validators::isStringNonEmpty($r['problem_alias'], 'problem_alias');

        try {
            $problem = ProblemsDAO::getByAlias($r['problem_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($problem)) {
            throw new InvalidParameterException('parameterNotFound', 'problem_alias');
        }

        return array(
            'contest' => $contest,
            'problem' => $problem);
    }

    /**
     * Validates add/remove user request
     *
     * @param Request $r
     * @throws InvalidDatabaseOperationException
     * @throws InvalidParameterException
     * @throws ForbiddenAccessException
     */
    private static function validateAddUser(Request $r) {
        $r['user'] = null;

        // Check contest_alias
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        $r['user'] = UserController::resolveUser($r['usernameOrEmail']);

        if (is_null($r['user'])) {
            throw new NotFoundException('userOrMailNotFound');
        }

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($r['contest'])) {
            throw new NotFoundException('contestNotFound');
        }

        // Only director is allowed to create problems in contest
        if (!Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
            throw new ForbiddenAccessException();
        }
    }

    /**
     * Adds a user to a contest.
     * By default, any user can view details of public contests.
     * Only users added through this API can view private contests
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws ForbiddenAccessException
     */
    public static function apiAddUser(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Authenticate logged user
        self::authenticateRequest($r);
        self::validateAddUser($r);

        $contest_user = new ContestsUsers();
        $contest_user->setContestId($r['contest']->getContestId());
        $contest_user->setUserId($r['user']->getUserId());
        $contest_user->setAccessTime('0000-00-00 00:00:00');
        $contest_user->setScore('0');
        $contest_user->setTime('0');

        // Save the contest to the DB
        try {
            ContestsUsersDAO::save($contest_user);
        } catch (Exception $e) {
            // Operation failed in the data layer
            self::$log->error('Failed to create new ContestUser: ' . $e->getMessage());
            throw new InvalidDatabaseOperationException($e);
        }

        return array('status' => 'ok');
    }

    /**
     * Remove a user from a private contest
     *
     * @param Request $r
     * @return type
     * @throws InvalidDatabaseOperationException
     */
    public static function apiRemoveUser(Request $r) {
        // Authenticate logged user
        self::authenticateRequest($r);
        self::validateAddUser($r);

        $contest_user = new ContestsUsers();
        $contest_user->setContestId($r['contest']->getContestId());
        $contest_user->setUserId($r['user']->getUserId());

        try {
            ContestsUsersDAO::delete($contest_user);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        return array('status' => 'ok');
    }

    /**
     * Adds an admin to a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws ForbiddenAccessException
     */
    public static function apiAddAdmin(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Authenticate logged user
        self::authenticateRequest($r);

        // Check contest_alias
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        $user = UserController::resolveUser($r['usernameOrEmail']);

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        // Only director is allowed to create problems in contest
        if (!Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
            throw new ForbiddenAccessException();
        }

        $contest_user = new UserRoles();
        $contest_user->setContestId($r['contest']->getContestId());
        $contest_user->setUserId($user->getUserId());
        $contest_user->setRoleId(CONTEST_ADMIN_ROLE);

        // Save the contest to the DB
        try {
            UserRolesDAO::save($contest_user);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        return array('status' => 'ok');
    }

    /**
     * Removes an admin from a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws ForbiddenAccessException
     */
    public static function apiRemoveAdmin(Request $r) {
        // Authenticate logged user
        self::authenticateRequest($r);

        // Check contest_alias
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        $r['user'] = UserController::resolveUser($r['usernameOrEmail']);

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        // Only admin is alowed to make modifications
        if (!Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
            throw new ForbiddenAccessException();
        }

        // Check if admin to delete is actually an admin
        if (!Authorization::IsContestAdmin($r['user']->getUserId(), $r['contest'])) {
            throw new NotFoundException();
        }

        $contest_user = new UserRoles();
        $contest_user->setContestId($r['contest']->getContestId());
        $contest_user->setUserId($r['user']->getUserId());
        $contest_user->setRoleId(CONTEST_ADMIN_ROLE);

        // Delete the role
        try {
            UserRolesDAO::delete($contest_user);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        return array('status' => 'ok');
    }

    /**
     * Adds an group admin to a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws ForbiddenAccessException
     */
    public static function apiAddGroupAdmin(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Authenticate logged user
        self::authenticateRequest($r);

        // Check contest_alias
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        $group = GroupsDAO::FindByAlias($r['group']);

        if ($group == null) {
            throw new InvalidParameterException('invalidParameters');
        }

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        // Only admins are allowed to modify contest
        if (!Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
            throw new ForbiddenAccessException();
        }

        $group_role = new GroupRoles();
        $group_role->setContestId($r['contest']->getContestId());
        $group_role->setGroupId($group->group_id);
        $group_role->setRoleId(CONTEST_ADMIN_ROLE);

        // Save the contest to the DB
        try {
            GroupRolesDAO::save($group_role);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        return array('status' => 'ok');
    }

    /**
     * Removes a group admin from a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws ForbiddenAccessException
     */
    public static function apiRemoveGroupAdmin(Request $r) {
        // Authenticate logged user
        self::authenticateRequest($r);

        // Check contest_alias
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        $group = GroupsDAO::FindByAlias($r['group']);

        if ($group == null) {
            throw new InvalidParameterException('invalidParameters');
        }

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        // Only admin is alowed to make modifications
        if (!Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
            throw new ForbiddenAccessException();
        }

        $group_role = new GroupRoles();
        $group_role->setContestId($r['contest']->getContestId());
        $group_role->setGroupId($group->group_id);
        $group_role->setRoleId(CONTEST_ADMIN_ROLE);

        // Delete the role
        try {
            GroupRolesDAO::delete($group_role);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        return array('status' => 'ok');
    }

    /**
     * Validate the Clarifications request
     *
     * @param Request $r
     * @throws InvalidDatabaseOperationException
     */
    private static function validateClarifications(Request $r) {
        // Check contest_alias
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        if ($r['contest'] == null) {
            throw new NotFoundException('contestNotFound');
        }

        Validators::isNumber($r['offset'], 'offset', false /* optional */);
        Validators::isNumber($r['rowcount'], 'rowcount', false /* optional */);
    }

    /**
     *
     * Get clarifications of a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiClarifications(Request $r) {
        self::authenticateRequest($r);
        self::validateClarifications($r);

        $is_contest_director = Authorization::IsContestAdmin(
            $r['current_user_id'],
            $r['contest']
        );

        try {
            $clarifications = ClarificationsDAO::GetContestClarifications(
                $r['contest']->getContestId(),
                $is_contest_director,
                $r['current_user_id'],
                $r['offset'],
                $r['rowcount']
            );
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        foreach ($clarifications as &$clar) {
            $clar['time'] = (int)$clar['time'];
        }

        // Add response to array
        $response = array();
        $response['clarifications'] = $clarifications;
        $response['status'] = 'ok';

        return $response;
    }

    /**
     * Returns the Scoreboard events
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws NotFoundException
     */
    public static function apiScoreboardEvents(Request $r) {
        // Get the current user
        self::validateDetails($r);

        // Create scoreboard
        $scoreboard = new Scoreboard(
            $r['contest']->getContestId(),
            Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])
        );

        // Push scoreboard data in response
        $response = array();
        $response['events'] = $scoreboard->events();

        return $response;
    }

    /**
     * Returns the Scoreboard
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws NotFoundException
     */
    public static function apiScoreboard(Request $r) {
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        // If true, will override Scoreboard Pertentage to 100%
        $showAllRuns = false;

        if (is_null($r['token'])) {
            // Get the current user
            self::authenticateRequest($r);

            self::canAccessContest($r);

            if (Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
                $showAllRuns = true;
            }
        } else {
            if ($r['token'] === $r['contest']->getScoreboardUrl()) {
                $showAllRuns = false;
            } elseif ($r['token'] === $r['contest']->getScoreboardUrlAdmin()) {
                $showAllRuns = true;
            } else {
                throw new ForbiddenAccessException('invalidScoreboardUrl');
            }
        }

        // Create scoreboard
        $scoreboard = new Scoreboard(
            $r['contest']->getContestId(),
            $showAllRuns
        );

        return $scoreboard->generate();
    }

    /**
     * Gets the accomulative scoreboard for an array of contests
     *
     * @param Request $r
     */
    public static function apiScoreboardMerge(Request $r) {
        // Get the current user
        self::authenticateRequest($r);

        Validators::isStringNonEmpty($r['contest_aliases'], 'contest_aliases');
        $contest_aliases = explode(',', $r['contest_aliases']);

        Validators::isStringNonEmpty($r['usernames_filter'], 'usernames_filter', false);

        $usernames_filter = array();
        if (isset($r['usernames_filter'])) {
            $usernames_filter = explode(',', $r['usernames_filter']);
        }

        // Validate all contest alias
        $contests = array();
        foreach ($contest_aliases as $contest_alias) {
            try {
                $contest = ContestsDAO::getByAlias($contest_alias);
            } catch (Exception $e) {
                // Operation failed in the data layer
                throw new InvalidDatabaseOperationException($e);
            }

            if (is_null($contest)) {
                throw new NotFoundException('contestNotFound');
            }

            array_push($contests, $contest);
        }

        // Get all scoreboards
        $scoreboards = array();
        foreach ($contests as $contest) {
            // Set defaults for contests params
            if (!isset($r['contest_params'][$contest->alias]['only_ac'])) {
                // Hay que hacer esto para evitar "Indirect modification of overloaded element of Request has no effect"
                // http://stackoverflow.com/questions/20053269/indirect-modification-of-overloaded-element-of-splfixedarray-has-no-effect
                $cp = $r['contest_params'];
                $cp[$contest->alias]['only_ac'] = false;
                $r['contest_params'] = $cp;
            }

            if (!isset($r['contest_params'][$contest->alias]['weight'])) {
                // Ditto indirect modification.
                $cp = $r['contest_params'];
                $cp[$contest->alias]['weight'] = 1;
                $r['contest_params'] = $cp;
            }

            $s = new Scoreboard(
                $contest->contest_id,
                false, /*showAllRuns*/
                null, /*auth_token*/
                $r['contest_params'][$contest->alias]['only_ac']
            );

            $scoreboards[$contest->alias] = $s->generate();
        }

        $merged_scoreboard = array();

        // Merge
        foreach ($scoreboards as $contest_alias => $scoreboard) {
            foreach ($scoreboard['ranking'] as $user_results) {
                // If user haven't been added to the merged scoredboard, add him
                if (!isset($merged_scoreboard[$user_results['username']])) {
                    $merged_scoreboard[$user_results['username']] = array();
                    $merged_scoreboard[$user_results['username']]['name'] = $user_results['name'];
                    $merged_scoreboard[$user_results['username']]['username'] = $user_results['username'];
                    $merged_scoreboard[$user_results['username']]['total']['points'] = 0;
                    $merged_scoreboard[$user_results['username']]['total']['penalty'] = 0;
                }

                $merged_scoreboard[$user_results['username']]['contests'][$contest_alias]['points'] = ($user_results['total']['points'] * $r['contest_params'][$contest_alias]['weight']);
                $merged_scoreboard[$user_results['username']]['contests'][$contest_alias]['penalty'] = $user_results['total']['penalty'];

                $merged_scoreboard[$user_results['username']]['total']['points'] += ($user_results['total']['points'] * $r['contest_params'][$contest_alias]['weight']);
                $merged_scoreboard[$user_results['username']]['total']['penalty'] += $user_results['total']['penalty'];
            }
        }

        // Remove users not in filter
        if (isset($r['usernames_filter'])) {
            foreach ($merged_scoreboard as $username => $entry) {
                if (array_search($username, $usernames_filter) === false) {
                    unset($merged_scoreboard[$username]);
                }
            }
        }

        // Normalize user["contests"] entries so all contain the same contests
        foreach ($merged_scoreboard as $username => $entry) {
            foreach ($contests as $contest) {
                if (!isset($entry['contests'][$contest->getAlias()]['points'])) {
                    $merged_scoreboard[$username]['contests'][$contest->getAlias()]['points'] = 0;
                    $merged_scoreboard[$username]['contests'][$contest->getAlias()]['penalty'] = 0;
                }
            }
        }

        // Sort merged_scoreboard
        usort($merged_scoreboard, array('self', 'compareUserScores'));

        $response = array();
        $response['ranking'] = $merged_scoreboard;
        $response['status'] = 'ok';

        return $response;
    }

    /**
     * Compares results of 2 contestants to sort them in the scoreboard
     *
     * @param type $a
     * @param type $b
     * @return int
     */
    private static function compareUserScores($a, $b) {
        if ($a['total']['points'] == $b['total']['points']) {
            if ($a['total']['penalty'] == $b['total']['penalty']) {
                return 0;
            }

            return ($a['total']['penalty'] > $b['total']['penalty']) ? 1 : -1;
        }

        return ($a['total']['points'] < $b['total']['points']) ? 1 : -1;
    }

    public static function apiRequests(Request $r) {
        // Authenticate request
        self::authenticateRequest($r);

        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        try {
            $contest = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        if (!Authorization::IsContestAdmin($r['current_user_id'], $contest)) {
            throw new ForbiddenAccessException();
        }

        try {
            $db_results = ContestUserRequestDAO::getRequestsForContest($contest->getContestId());
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        // @TODO prefetch an alias-user_id map so that we dont need
        // a getbypk (sql select query) on every iteration of the following loop

        // Precalculate all admin profiles.
        $admin_infos = array();
        foreach ($db_results as $result) {
            $admin_id = $result['admin_id'];
            if (!array_key_exists($admin_id, $admin_infos)) {
                $data = UsersDAO::getByPK($admin_id);
                if (!is_null($data)) {
                    $admin_infos[$admin_id]['user_id'] = $data->user_id;
                    $admin_infos[$admin_id]['username'] = $data->username;
                    $admin_infos[$admin_id]['name'] = $data->name;
                }
            }
        }

        $users = array();
        foreach ($db_results as $result) {
            $admin_id = $result['admin_id'];

            $result = new ContestUserRequest($result);
            $user_id = $result->getUserId();
            $user = UsersDAO::getByPK($user_id);

            // Get user profile. Email, school, etc.
            $profile_request = new Request();
            $profile_request['username'] = $user->getUsername();
            $profile_request['omit_rank'] = true;

            $userprofile = UserController::apiProfile($profile_request);
            $adminprofile = array();

            if (array_key_exists($admin_id, $admin_infos)) {
                $adminprofile = $admin_infos[$admin_id];
            }

            $users[] = array_merge(
                $userprofile['userinfo'],
                array(
                    'last_update' => $result->last_update,
                    'accepted' => $result->accepted,
                    'extra_note' => $result->extra_note,
                    'admin' => $adminprofile,
                    'request_time' => $result->request_time)
            );
        }

        $response = array();
        $response['users'] = $users;
        $response['status'] = 'ok';

        return $response;
    }

    public static function apiArbitrateRequest(Request $r) {
        $result = array('status' => 'ok');

        if (is_null($r['resolution'])) {
            throw new InvalidParameterException('invalidParameters');
        }

        // user must be admin of contest to arbitrate security
        $current_ses = SessionController::getCurrentSession($r);

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            throw new NotFoundException($e);
        }

        if (is_null($r['contest'])) {
            throw new NotFoundException('contestNotFound');
        }

        $r['target_user'] = UsersDAO::FindByUsername($r['username']);

        $request = ContestUserRequestDAO::getByPK($r['target_user']->user_id, $r['contest']->contest_id);

        if (is_null($request)) {
            throw new InvalidParameterException('userNotInListOfRequests');
        }

        if ($r['resolution'] === 'false') {
            // "false" casts to true.
            $resolution = false;
        } else {
            $resolution = (bool)$r['resolution'];
        }

        $request->setAccepted($resolution);
        $request->setExtraNote($r['note']);
        $request->setLastUpdate(gmdate('Y-m-d H:i:s'));

        ContestUserRequestDAO::save($request);

        // Save this action in the history
        $history = new ContestUserRequestHistory();
        $history->user_id = $request->user_id;
        $history->contest_id = $request->user_id;
        $history->time = $request->last_update;
        $history->admin_id = $current_ses['id'];
        $history->accepted = $request->accepted;

        ContestUserRequestHistoryDAO::save($history);

        self::$log->info('Arbitrated contest for user, new accepted user_id='
                                . $r['target_user']->user_id . ', state=' . $resolution);

        return $result;
    }

    /**
     * Returns ALL users participating in a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiUsers(Request $r) {
        // Authenticate request
        self::authenticateRequest($r);

        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        try {
            $contest = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        if (!Authorization::IsContestAdmin($r['current_user_id'], $contest)) {
            throw new ForbiddenAccessException();
        }

        // Get users from DB
        $contest_user_key = new ContestsUsers();
        $contest_user_key->setContestId($contest->getContestId());

        try {
            $db_results = ContestsUsersDAO::search($contest_user_key);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        $users = array();

        // Add all users to an array
        foreach ($db_results as $result) {
            $user_id = $result->getUserId();
            $user = UsersDAO::getByPK($user_id);
            $users[] = array('user_id' => $user_id, 'username' => $user->getUsername(), 'access_time' => $result->access_time, 'country' => $user->getCountryId());
        }

        $response = array();
        $response['users'] = $users;
        $response['status'] = 'ok';

        return $response;
    }

    /**
     * Returns all contest administrators
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiAdmins(Request $r) {
        // Authenticate request
        self::authenticateRequest($r);

        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        try {
            $contest = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        if (!Authorization::IsContestAdmin($r['current_user_id'], $contest)) {
            throw new ForbiddenAccessException();
        }

        $response = array();
        $response['admins'] = UserRolesDAO::getContestAdmins($contest);
        $response['group_admins'] = GroupRolesDAO::getContestAdmins($contest);
        $response['status'] = 'ok';

        return $response;
    }

    /**
     * Enforces rules to avoid having invalid/unactionable public contests
     *
     * @param Contests $contest
     */
    private static function validateContestCanBePublic(Contests $contest) {
        // Check that contest has some problems at least 1 problem
        $problemsInContest = ContestProblemsDAO::GetRelevantProblems($contest->getContestId());
        if (count($problemsInContest) < 1) {
            throw new InvalidParameterException('contestPublicRequiresProblem');
        }
    }

    /**
     * Update a Contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiUpdate(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Authenticate request
        self::authenticateRequest($r);

        // Validate request
        self::validateCreateOrUpdate($r, true /* is update */);

        // Update contest DAO
        if (!is_null($r['public'])) {
            // If going public
            if ($r['public'] == 1) {
                self::validateContestCanBePublic($r['contest']);
            }

            $r['contest']->setPublic($r['public']);
        }

        $valueProperties = array(
            'title',
            'description',
            'start_time'        => array('transform' => function ($value) {
                return gmdate('Y-m-d H:i:s', $value);
            }),
            'finish_time'       => array('transform' => function ($value) {
                return gmdate('Y-m-d H:i:s', $value);
            }),
            'window_length' => array('transform' => function ($value) {
                return $value == 'NULL' ? null : $value;
            }),
            'scoreboard',
            'points_decay_factor',
            'partial_score',
            'submissions_gap',
            'feedback',
            'penalty'               => array('transform' => function ($value) {
                return max(0, intval($value));
            }),
            'penalty_type',
            'penalty_calc_policy',
            'show_scoreboard_after',
            'contestant_must_register',
        );
        self::updateValueProperties($r, $r['contest'], $valueProperties);

        // Push changes
        try {
            // Begin a new transaction
            ContestsDAO::transBegin();

            // Save the contest object with data sent by user to the database
            ContestsDAO::save($r['contest']);

            // If the contest is private, add the list of allowed users
            if (!is_null($r['public']) && $r['public'] != 1 && $r['hasPrivateUsers']) {
                // Get current users
                $cu_key = new ContestsUsers(array('contest_id' => $r['contest']->getContestId()));
                $current_users = ContestsUsersDAO::search($cu_key);
                $current_users_id = array();

                foreach ($current_users as $cu) {
                    array_push($current_users_id, $current_users->getUserId());
                }

                // Check who needs to be deleted and who needs to be added
                $to_delete = array_diff($current_users_id, $r['private_users_list']);
                $to_add = array_diff($r['private_users_list'], $current_users_id);

                // Add users in the request
                foreach ($to_add as $userkey) {
                    // Create a temp DAO for the relationship
                    $temp_user_contest = new ContestsUsers(array(
                                'contest_id' => $r['contest']->getContestId(),
                                'user_id' => $userkey,
                                'access_time' => '0000-00-00 00:00:00',
                                'score' => 0,
                                'time' => 0
                            ));

                    // Save the relationship in the DB
                    ContestsUsersDAO::save($temp_user_contest);
                }

                // Delete users
                foreach ($to_delete as $userkey) {
                    // Create a temp DAO for the relationship
                    $temp_user_contest = new ContestsUsers(array(
                                'contest_id' => $r['contest']->getContestId(),
                                'user_id' => $userkey,
                            ));

                    // Delete the relationship in the DB
                    ContestsUsersDAO::delete(ContestProblemsDAO::search($temp_user_contest));
                }
            }

            if (!is_null($r['problems'])) {
                // Get current problems
                $p_key = new Problems(array('contest_id' => $r['contest']->getContestId()));
                $current_problems = ProblemsDAO::search($p_key);
                $current_problems_id = array();

                foreach ($current_problems as $p) {
                    array_push($current_problems_id, $p->getProblemId());
                }

                // Check who needs to be deleted and who needs to be added
                $to_delete = array_diff($current_problems_id, self::$problems_id);
                $to_add = array_diff(self::$problems_id, $current_problems_id);

                foreach ($to_add as $problem) {
                    $contest_problem = new ContestProblems(array(
                                'contest_id' => $r['contest']->getContestId(),
                                'problem_id' => $problem,
                                'points' => $r['problems'][$problem]['points']
                            ));

                    ContestProblemsDAO::save($contest_problem);
                }

                foreach ($to_delete as $problem) {
                    $contest_problem = new ContestProblems(array(
                                'contest_id' => $r['contest']->getContestId(),
                                'problem_id' => $problem,
                            ));

                    ContestProblemsDAO::delete(ContestProblemsDAO::search($contest_problem));
                }
            }

            // End transaction
            ContestsDAO::transEnd();
        } catch (Exception $e) {
            // Operation failed in the data layer, rollback transaction
            ContestsDAO::transRollback();

            throw new InvalidDatabaseOperationException($e);
        }

        // Expire contest-info cache
        Cache::deleteFromCache(Cache::CONTEST_INFO, $r['contest_alias']);

        // Expire contest scoreboard cache
        Scoreboard::InvalidateScoreboardCache($r['contest']->getContestId());

        // Expire contes-list cache
        Cache::deleteFromCache(Cache::CONTESTS_LIST_PUBLIC);
        Cache::deleteFromCache(Cache::CONTESTS_LIST_SYSTEM_ADMIN);

        // Happy ending
        $response = array();
        $response['status'] = 'ok';

        self::$log->info('Contest updated (alias): ' . $r['contest_alias']);

        return $response;
    }

    /**
     * Validates runs API
     *
     * @param Request $r
     * @throws InvalidDatabaseOperationException
     * @throws NotFoundException
     * @throws ForbiddenAccessException
     */
    private static function validateRuns(Request $r) {
        // Defaults for offset and rowcount
        if (!isset($r['offset'])) {
            $r['offset'] = 0;
        }
        if (!isset($r['rowcount'])) {
            $r['rowcount'] = 100;
        }

        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($r['contest'])) {
            throw new NotFoundException('contestNotFound');
        }

        if (!Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
            throw new ForbiddenAccessException('userNotAllowed');
        }

        Validators::isNumber($r['offset'], 'offset', false);
        Validators::isNumber($r['rowcount'], 'rowcount', false);
        Validators::isInEnum($r['status'], 'status', array('new', 'waiting', 'compiling', 'running', 'ready'), false);
        Validators::isInEnum($r['verdict'], 'verdict', array('AC', 'PA', 'WA', 'TLE', 'MLE', 'OLE', 'RTE', 'RFE', 'CE', 'JE', 'NO-AC'), false);

        // Check filter by problem, is optional
        if (!is_null($r['problem_alias'])) {
            Validators::isStringNonEmpty($r['problem_alias'], 'problem');

            try {
                $r['problem'] = ProblemsDAO::getByAlias($r['problem_alias']);
            } catch (Exception $e) {
                // Operation failed in the data layer
                throw new InvalidDatabaseOperationException($e);
            }

            if (is_null($r['problem'])) {
                throw new NotFoundException('problemNotFound');
            }
        }

        Validators::isInEnum($r['language'], 'language', array('c', 'cpp', 'cpp11', 'java', 'py', 'rb', 'pl', 'cs', 'pas', 'kp', 'kj'), false);

        // Get user if we have something in username
        if (!is_null($r['username'])) {
            $r['user'] = UserController::resolveUser($r['username']);
        }
    }

    /**
     * Returns all runs for a contest
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiRuns(Request $r) {
        // Authenticate request
        self::authenticateRequest($r);

        // Validate request
        self::validateRuns($r);

        // Get our runs
        try {
            $runs = RunsDAO::GetAllRuns(
                $r['contest']->getContestId(),
                $r['status'],
                $r['verdict'],
                !is_null($r['problem']) ? $r['problem']->getProblemId() : null,
                $r['language'],
                !is_null($r['user']) ? $r['user']->getUserId() : null,
                $r['offset'],
                $r['rowcount']
            );
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        $result = array();

        foreach ($runs as $run) {
            $run['time'] = (int)$run['time'];
            $run['score'] = round((float)$run['score'], 4);
            $run['contest_score'] = round((float)$run['contest_score'], 2);
            array_push($result, $run);
        }

        $response = array();
        $response['runs'] = $result;
        $response['status'] = 'ok';

        return $response;
    }

    /**
     * Validates that request contains contest_alias and the api is contest-admin only
     *
     * @param Request $r
     * @throws InvalidDatabaseOperationException
     * @throws ForbiddenAccessException
     */
    private static function validateStats(Request $r) {
        Validators::isStringNonEmpty($r['contest_alias'], 'contest_alias');

        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        // This API is Contest Admin only
        if (is_null($r['contest']) || !Authorization::IsContestAdmin($r['current_user_id'], $r['contest'])) {
            throw new ForbiddenAccessException('userNotAllowed');
        }
    }

    /**
     * Stats of a problem
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws ForbiddenAccessException
     */
    public static function apiStats(Request $r) {
        // Get user
        self::authenticateRequest($r);

        self::validateStats($r);

        try {
            // Array of GUIDs of pending runs
            $pendingRunsGuids = RunsDAO::GetPendingRunsOfContest($r['contest']->getContestId());

            // Count of pending runs (int)
            $totalRunsCount = RunsDAO::CountTotalRunsOfContest($r['contest']->getContestId());

            // Wait time
            $waitTimeArray = RunsDAO::GetLargestWaitTimeOfContest($r['contest']->getContestId());

            // List of verdicts
            $verdict_counts = array();

            foreach (self::$verdicts as $verdict) {
                $verdict_counts[$verdict] = RunsDAO::CountTotalRunsOfContestByVerdict($r['contest']->getContestId(), $verdict);
            }

            // Get max points posible for contest
            $key = new ContestProblems(array('contest_id' => $r['contest']->getContestId()));
            $contestProblems = ContestProblemsDAO::search($key);
            $totalPoints = 0;
            foreach ($contestProblems as $cP) {
                $totalPoints += $cP->getPoints();
            }

            // Get scoreboard to calculate distribution
            $distribution = array();
            for ($i = 0; $i < 101; $i++) {
                $distribution[$i] = 0;
            }

            $sizeOfBucket = $totalPoints / 100;
            $scoreboardResponse = self::apiScoreboard($r);
            foreach ($scoreboardResponse['ranking'] as $results) {
                $distribution[(int)($results['total']['points'] / $sizeOfBucket)]++;
            }
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        // Para darle gusto al Alanboy, regresando array
        return array(
            'total_runs' => $totalRunsCount,
            'pending_runs' => $pendingRunsGuids,
            'max_wait_time' => is_null($waitTimeArray) ? 0 : $waitTimeArray[1],
            'max_wait_time_guid' => is_null($waitTimeArray) ? 0 : $waitTimeArray[0]->getGuid(),
            'verdict_counts' => $verdict_counts,
            'distribution' => $distribution,
            'size_of_bucket' => $sizeOfBucket,
            'total_points' => $totalPoints,
            'status' => 'ok',
        );
    }

    /**
     * Returns a detailed report of the contest
     *
     * @param Request $r
     * @return array
     */
    public static function apiReport(Request $r) {
        self::authenticateRequest($r);

        self::validateStats($r);

        $scoreboard = new Scoreboard(
            $r['contest']->getContestId(),
            true, //Show only relevant runs
            $r['auth_token']
        );

        // Check the filter if we have one
        Validators::isStringNonEmpty($r['filterBy'], 'filterBy', false /* not required */);

        $contestReport = $scoreboard->generate(
            true, // with run details for reporting
            true, // sort contestants by name,
            (isset($r['filterBy']) ? null : $r['filterBy'])
        );

        $contestReport['status'] = 'ok';
        return $contestReport;
    }

    /**
     * Generates a CSV for contest report
     *
     * @param Request $r
     * @return array
     */
    public static function apiCsvReport(Request $r) {
        self::authenticateRequest($r);

        self::validateStats($r);

        // Get full Report API of the contest
        $reportRequest = new Request(array(
                    'contest_alias' => $r['contest_alias'],
                    'auth_token' => $r['auth_token'],
                ));
        $contestReport = self::apiReport($reportRequest);

        // Get problem stats for each contest problem so we can
        // have the full list of cases
        $problemStats = array();
        $i = 0;
        foreach ($contestReport['problems'] as $entry) {
            $problem_alias = $entry['alias'];
            $problemStatsRequest = new Request(array(
                        'problem_alias' => $problem_alias,
                        'auth_token' => $r['auth_token'],
                    ));

            $problemStats[$i] = ProblemController::apiStats($problemStatsRequest);
            $problemStats[$problem_alias] = $problemStats[$i];

            $i++;
        }

        // Build a csv
        $csvData = array();

        // Build titles
        $csvRow = array();
        $csvRow[] = 'username';
        foreach ($contestReport['problems'] as $entry) {
            foreach ($problemStats[$entry['alias']]['cases_stats'] as $caseName => $counts) {
                $csvRow[] = $caseName;
            }
            $csvRow[] = $entry['alias'] . ' total';
        }
        $csvRow[] = 'total';
        $csvData[] = $csvRow;

        foreach ($contestReport['ranking'] as $userData) {
            if ($userData === 'ok') {
                continue;
            }

            $csvRow = array();
            $csvRow[] = $userData['username'];

            foreach ($userData['problems'] as $key => $problemData) {
                // If the user don't have these details then he didn't submit,
                // we need to fill the report with 0s for completeness
                if (!isset($problemData['run_details']['cases']) || count($problemData['run_details']['cases']) === 0) {
                    for ($i = 0; $i < count($problemStats[$key]['cases_stats']); $i++) {
                        $csvRow[] = '0';
                    }

                    // And adding the total for this problem
                    $csvRow[] = '0';
                } else {
                    // for each case
                    foreach ($problemData['run_details']['cases'] as $caseData) {
                        // If case is correct
                        if (strcmp($caseData['meta']['status'], 'OK') === 0 && strcmp($caseData['out_diff'], '') === 0) {
                            $csvRow[] = '1';
                        } else {
                            $csvRow[] = '0';
                        }
                    }

                    $csvRow[] = $problemData['points'];
                }
            }
            $csvRow[] = $userData['total']['points'];
            $csvData[] = $csvRow;
        }

        // Set headers to auto-download file
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Type: application/force-download');
        header('Content-Type: application/octet-stream');
        header('Content-Type: application/download');
        header('Content-Disposition: attachment;filename=' . $r['contest_alias'] . '_report.csv');
        header('Content-Transfer-Encoding: binary');

        // Write contents to a csv raw string
        // TODO(https://github.com/omegaup/omegaup/issues/628): Escape = to prevent applications from inadvertently executing code
        // http://contextis.co.uk/blog/comma-separated-vulnerabilities/
        $out = fopen('php://output', 'w');
        foreach ($csvData as $csvRow) {
            fputcsv($out, ContestController::escapeCsv($csvRow));
        }
        fclose($out);

        // X_X
        die();
    }

    private static function escapeCsv($csvRow) {
        $escapedRow = array();
        foreach ($csvRow as $field) {
            if (is_string($field) && $field[0] == '=') {
                $escapedRow[] = "'" . $field;
            } else {
                $escapedRow[] = $field;
            }
        }
        return $escapedRow;
    }

    public static function apiDownload(Request $r) {
        self::authenticateRequest($r);

        self::validateStats($r);

        // Get our runs
        $relevant_columns = array('run_id', 'guid', 'language', 'status',
            'verdict', 'runtime', 'penalty', 'memory', 'score', 'contest_score',
            'time', 'submit_delay', 'Users.username', 'Problems.alias');
        try {
            $runs = RunsDAO::search(new Runs(array(
                                'contest_id' => $r['contest']->getContestId()
                            )), 'time', 'DESC', $relevant_columns);
        } catch (Exception $e) {
            // Operation failed in the data layer
            throw new InvalidDatabaseOperationException($e);
        }

        $zip = new ZipStream($r['contest_alias'] . '.zip');

        // Add runs to zip
        $table = "guid,user,problem,verdict,points\n";
        foreach ($runs as $run) {
            $zip->add_file_from_path(
                'runs/' . $run->getGuid(),
                RunController::getSubmissionPath($run)
            );

            $columns[0] = 'username';
            $columns[1] = 'alias';
            $usernameProblemData = $run->asFilteredArray($columns);

            $table .= $run->getGuid() . ',' . $usernameProblemData['username'] . ',' . $usernameProblemData['alias'] . ',' . $run->getVerdict() . ',' . $run->getContestScore();
            $table .= "\n";
        }

        $zip->add_file('summary.csv', $table);

        // Add problem cases to zip
        $contest_problems = ContestProblemsDAO::GetRelevantProblems($r['contest']->getContestId());
        foreach ($contest_problems as $problem) {
            $zip->add_file_from_path($problem->getAlias() . '_cases.zip', PROBLEMS_PATH . '/' . $problem->getAlias() . '/cases.zip');
        }

        // Return zip
        $zip->finish();
        die();
    }

    /**
     * Given a contest_alias and user_id, returns the role of the user within
     * the context of a contest.
     *
     * @param Request $r
     * @return array
     */
    public static function apiRole(Request $r) {
        try {
            if ($r['contest_alias'] == 'all-events') {
                self::authenticateRequest($r);
                if (Authorization::IsSystemAdmin($r['current_user_id'])) {
                    return array(
                        'status' => 'ok',
                        'admin' => true
                    );
                }
            }

            self::validateDetails($r);

            return array(
                'status' => 'ok',
                'admin' => $r['contest_admin']
            );
        } catch (Exception $e) {
            self::$log->error('Error getting role: ' . $e);

            return array(
                'status' => 'error',
                'admin' => false
            );
        }
    }

    /**
     * Given a contest_alias, sets the recommended flag on/off.
     * Only omegaUp admins can call this API.
     *
     * @param Request $r
     * @return array
     */
    public static function apiSetRecommended(Request $r) {
        self::authenticateRequest($r);

        if (!Authorization::IsSystemAdmin($r['current_user_id'])) {
            throw new ForbiddenAccessException('userNotAllowed');
        }

        // Validate & get contest_alias
        try {
            $r['contest'] = ContestsDAO::getByAlias($r['contest_alias']);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($r['contest'])) {
            throw new NotFoundException('contestNotFound');
        }

        // Validate value param
        Validators::isInEnum($r['value'], 'value', array('0', '1'));

        $r['contest']->recommended = $r['value'];

        try {
            ContestsDAO::save($r['contest']);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        return array('status' => 'ok');
    }
}
