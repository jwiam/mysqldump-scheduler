<?php

date_default_timezone_set('Asia/Seoul');
// error_reporting(E_ALL);
// ini_set('display_errors', TRUE);
ini_set('memory_limit','-1');
// set_time_limit(0);

class MySQLDump
{
    /**
     * 백업 할 데이터베이스 정보
     */
    const DATABASE_INI = 'database.ini';

    /**
     * 덤프 뜰 로컬 데이터베이스 및 슬랙 주소 등
     */
    const SETTINGS_INI = 'settings.ini';

    /**
     * 로컬 데이터베이스 및 슬랙 내에 있는 WebHooks 앱 URL 설정값
     * ['slack-url' => '', 'localhost-username' => 'root', 'localhost-password' => 'root', 'localhost-host' => 'localhost']
     *
     * @var array|false
     */
    public $localSettings = false;

    /**
     * 리모트 데이터베이스 설정값
     *
     * @var array|false
     */
    public $remoteDatabases = false;

    /**
     * 덤프 및 설정 파일 관련 기본 루트 경로
     *
     * @var string
     */
    public $rootPath = './';

    /**
     * 백업 시간 기록
     *
     * @var int
     */
    public $elapsedTime = 0;

    /**
     * 로컬 및 리모트 데이터베이스 설정
     */
    function __construct()
    {
        // 파일이 존재하지 않는 경우
        if (!file_exists($this->rootPath . self::SETTINGS_INI)
        || !file_exists($this->rootPath . self::DATABASE_INI)) {
            exit('.ini 파일이 존재하지 않습니다.');
        }

        // 로컬 데이터베이스 및 슬랙 주소 설정
        $this->localSettings = parse_ini_file($this->rootPath . self::SETTINGS_INI);

        // 리모트 데이터베이스 설정
        $this->remoteDatabases = parse_ini_file($this->rootPath . self::DATABASE_INI, true);

        // ini 파일이 파싱되지 않은 경우
        if (!$this->localSettings || !$this->remoteDatabases) {
            exit('.ini 파일 내용을 확인해주세요.');
        }
    }

    function execute()
    {
        $this->slack("　\n ---------- 스크립트 시작 ---------- \n　");

        // 스크립트 실행 시간
        $this->elapsedTime = time();

        $dumpResult = $this->dump();
        $localBackupResult = $this->localBackup($dumpResult);

        // 스크립트 완료 시간
        $totalElapsedTime = time() - $this->elapsedTime;

        // 백업 결과 슬랙 메시지로 보내기
        $this->slack(
            $this->customSlackMessage(
                $dumpResult,
                $localBackupResult,
                $totalElapsedTime
            )
        );
    }

    /**
     * 로컬에 MySQL DB 데이터 덤프 백업
     * 
     * @return array 덤프 백업 결과
     */
    function dump():array
    {
        $return = [
            'success' => [
                'filename' => []
            ], 
            'failed' => [
                'filename' => [], 
                'messages' => []
            ]
        ];

        foreach ($this->remoteDatabases as $title => $info) {
            $host = $info['host'];
            $username = $info['username'];
            $password = str_replace('^', '^^', $info['password']);
            $database = (array) $info['database'];
            $ignoreTable = (array) $info['ignore-table'];

            // DB 단위로 백업
            foreach ($database as $idx => $db) {
                // 예외 테이블 구문 생성
                if (!empty($ignoreTable[$idx])) {
                    $ignoreTable[$idx] = array_map('trim', explode(',', $ignoreTable[$idx]));
                    $ignoreTable[$idx] = "--ignore-table={$db}." . implode(" --ignore-table={$db}.", $ignoreTable[$idx]);
                } else {
                    $ignoreTable[$idx] = '';
                }

                // 덤프 파일 이름
                $sqlFilename = "dump-{$title}-{$db}.sql";

                $output = [];
                $command = "mysqldump.exe -u {$username} -p{$password} -h {$host} {$ignoreTable[$idx]} --set-gtid-purged=OFF --databases --add-drop-database --single-transaction {$db} -r {$this->rootPath}{$sqlFilename} 2>&1";

                // mysqldump 커맨드 실행
                exec($command, $output, $resultCode);

                // 결과 기록
                if ($resultCode === 0) {
                    $return['success']['filename'][] = $sqlFilename;
                } else {
                    $return['failed']['filename'][] = $sqlFilename;
                    $return['failed']['messages'][] = $this->getExecError($output); // 실패 메시지
                }
            }
        }

        return $return;
    }

    /**
     * 로컬 데이터베이스에 덤프 된 파일을 이용해서 복구 백업
     * 
     * @var array $dumpResult 덤프 백업 결과
     * @return array 복구 백업 결과
     */
    function localBackup(array $dumpResult = []):array
    {
        $return = [
            'success' => [
                'filename' => []
            ], 
            'failed' => [
                'filename' => [],
                'messages' => []
            ]
        ];

        foreach ($dumpResult as $resultKey => $result) {
            foreach ($result['filename'] as $sqlFilename) {
                // mysqldump 성공한 SQL 파일만 로컬 데이터베이스에 복구 백업 진행
                if ($resultKey === 'success') {
                    $output = [];
                    $command = "mysql.exe -u {$this->localSettings['localhost-username']} -p{$this->localSettings['localhost-password']} -h {$this->localSettings['localhost-host']} < {$this->rootPath}{$sqlFilename} 2>&1";

                    // mysql 커맨드 실행
                    exec($command, $output, $resultCode);

                    // 결과 기록
                    if ($resultCode === 0) {
                        $return['success']['filename'][] = $sqlFilename;
                    } else {
                        $return['failed']['filename'][] = $sqlFilename;
                        $return['failed']['messages'][] = $this->getExecError($output); // 실패 메시지
                    }
                }

                // 생성 된 SQL 파일 별도 관리
                $this->fileManager($sqlFilename);
            }
        }
        
        return $return;
    }

    /**
     * 생성 된 SQL 파일 별도 관리
     * 30일 지난 폴더는 삭제
     * 
     * @var string $sqlFilename 파일명
     */
    function fileManager(string $sqlFilename = '')
    {
        $sqlStorage = "{$this->rootPath}/sql_storage/";
        $todayStorage = $sqlStorage . date('Ymd') . '/';

        // SQL 파일 저장 폴더 생성
        if (!is_dir($sqlStorage)) {
            mkdir($sqlStorage);
        }

        // SQL 파일 저장 폴더 하위에 오늘 날짜의 폴더 생성
        if (!is_dir($todayStorage)) {
            mkdir($todayStorage);
        }

        // 생성 된 SQL 파일을 오늘 날짜의 폴더로 이동
        if (file_exists($this->rootPath . $sqlFilename)) {
            rename($this->rootPath . $sqlFilename, $todayStorage . $sqlFilename);
        }

        // '.', '..' 폴더를 제외하고, 30일 지난 폴더 삭제
        foreach (array_diff(scandir($sqlStorage), ['.', '..']) as $folderName) {
            $folderPathname = $sqlStorage . $folderName;
            
            $folderCreatedTime = time() - filemtime($folderPathname);
            $folderCreatedDate = (int) ($folderCreatedTime / 60 / 60 / 24);

            // 30일 체크
            if ($folderCreatedDate >= 30) {
                // 폴더 내 파일 삭제
                array_map('unlink', glob("{$folderPathname}/*.*"));

                // 폴더 삭제
                rmdir($folderPathname);
            }
        }
    }

    /**
     * Incoming WebHooks 앱 사용
     * 채널에 앱 추가 후 웹훅 URL 생성
     * 
     * @var string $message 슬랙으로 보낼 메시지
     */
    function slack(string $message = '')
    {
        $payload = ['text' => $message];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->localSettings['slack-url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['payload' => json_encode($payload)]
        ]);

        curl_exec($curl);

        curl_close($curl);
    }

    /**
     * exec 함수 output 값 중, 에러 구문 확인 후 리턴
     * 
     * @var array $output
     * @return string
     */
    function getExecError(array $output = []):string
    {
        $errorMessages = array_filter($output, function($value, $key) {
            // mysqldump error
            if (is_numeric(strpos($value, 'Got error'))) {
                return $value;
            }

            // mysql error
            if (is_numeric(strpos($value, 'ERROR'))) {
                return $value;
            }

            // system error (stderr)
            if (is_numeric(strpos($value, 'System error'))) {
                return $value;
            }

            return '에러 메시지를 확인 할 수 없습니다.';
        }, ARRAY_FILTER_USE_BOTH);

        return trim(implode("\n - ", $errorMessages));
    }

    /**
     * 덤프 백업 및 로컬 데이터베이스 복구 결과 메시지 생성
     * line break(\n) 사용 시, 쌍따옴표 필요
     * 
     * @var array $dumpResult 덤프 백업 결과
     * @var array $localBackupResult 로컬 데이터베이스 복구 결과
     * @var int $totalElapsedTime 스크립트 최종 경과 시간
     * @return string 슬랙 메시지
     */
    function customSlackMessage(array $dumpResult = [], array $localBackupResult = [], int $totalElapsedTime = 0):string
    {
        // 경과 시간
        $elapsedHours = (int) ($totalElapsedTime / 60 / 60);
        $elapsedMinutes = $totalElapsedTime / 60 % 60;
        $elapsedSeconds = $totalElapsedTime % 60;

        // 덤프 백업 결과
        $successDumpCount = count($dumpResult['success']['filename']);
        $failedDumpCount = count($dumpResult['failed']['filename']);

        // 로컬 데이터베이스 복구 결과
        $successLocalBackupCount = count($localBackupResult['success']['filename']);
        $failedLocalBackupCount = count($localBackupResult['failed']['filename']);

        // 덤프 백업 실패 파일 및 메시지
        $failedDumpFile = ' - ' . implode("\n - ", $dumpResult['failed']['filename']);
        $failedDumpMessage = ' - ' . implode("\n - ", $dumpResult['failed']['messages']);

        // 로컬 데이터베이스 복구 실패 파일 및 메시지
        $failedLocalBackupFile = ' - ' . implode("\n - ", $localBackupResult['failed']['filename']);
        $failedLocalBackupMessage = ' - ' . implode("\n - ", $localBackupResult['failed']['messages']);

        $slackMessage = "
            📢
            \n경과 시간: {$elapsedHours}시간 {$elapsedMinutes}분 {$elapsedSeconds}초
            
            \n\n덤프 백업 성공: {$successDumpCount}, 덤프 백업 실패: {$failedDumpCount}
            \n로컬 데이터베이스 복구 성공: {$successLocalBackupCount}, 로컬 데이터베이스 복구 실패: {$failedLocalBackupCount}
        ";

        // 덤프 백업에 실패한 파일이 있는 경우
        if ($failedDumpCount > 0) {
            $slackMessage .= "
                \n\n덤프 백업 실패 목록
                \n{$failedDumpFile}
                \n덤프 백업 실패 메시지
                \n{$failedDumpMessage}
            ";
        }

        // 로컬 데이터베이스 복구에 실패한 파일이 있는 경우
        if ($failedLocalBackupCount > 0) {
            $slackMessage .= "
                \n\n로컬 데이터베이스 복구 실패 목록
                \n{$failedLocalBackupFile}
                \n로컬 데이터베이스 복구 실패 메시지
                \n{$failedLocalBackupMessage}
            ";
        }
        
        return $slackMessage;
    }

    /**
     * TODO: exec 함수에서 $output 값에 표시되지 않는 시스템 에러 (stderr) 검출 용도
     *       에러 예시) The system cannot find the file specified.
     *
     * @var string $command 실행 할 커맨드 명령어
     * @return false|string|void
     */
    function myExec(string $command = '')
    {
        $return = [];

        $descriptorSpec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];
    
        $proc = proc_open($command, $descriptorSpec, $pipes);
    
        if (is_resource($proc)) {
            $output = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
    
            $output = array_filter(array_map('trim', explode("\n", $output)));
            $stderr = array_filter(array_map('trim', explode("\n", $stderr)));
            $stderr = preg_filter('/^/', 'System error - ', $stderr);

            $return = array_merge($output, $stderr);
            proc_close($proc);
        } else {
            exit('System error - proc_open is not resource.');
        }

        return $return;
    }
}

(new MySQLDump())->execute();