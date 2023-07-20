### ini 파일 설정 및 환경설정

1. `database.ini` 리모트 데이터베이스 정보 확인
2. `settings.ini` 로컬 데이터베이스 및 슬랙 앱 URL 확인
3. PHP 7.3.27 다운로드 후 환경변수 등록
   - https://windows.php.net/downloads/releases/archives/
   - php-7.3.27-Win32-VC15-x64.zip 설치
   - php.ini 파일 생성
   - extension_dir = "ext"
   - curl, fileinfo, gd2, intl, mbstring, mysqli, openssl, pdo_mysql 등 필요한 라이브러리 주석 제거
4. MySQL 5.7.33 다운로드 후 환경변수 등록
   - https://downloads.mysql.com/archives/installer/
   - username/password : root/root
   - mysql-installer-community-5.7.33.0.msi 설치
5. `mysqldump.bat`, `MySQLDump.php` 두 파일은 같은 경로 내에 위치
6. `mysqldump.bat` 파일은 윈도우 작업 스케줄러 등록
   - Window + R 키를 눌러 `taskschd.msc` 입력하거나 앱에서 `작업 스케줄러` 검색

---

### 참고 및 주의사항

__※__ 작업 스케줄러 등록할 때는 `MySQLDump.php` 파일의 ___$rootPath___ 및 `mysqldump.bat` 파일의 ___실행파일경로___  값은 절대 경로로 변경 필요

__※__ 환경변수 등록 후 커맨드 실행할 때 프로세스가 무한히 생성 된다면, 명령어에 `.exe` 확장자 붙여서 실행

__※__ 로컬 데이터베이스에 복구 할 때 `<` 연산자가 예약어로 되어있는 경우, 아래 커맨드 사용해서 복구 
```
Get-Content restore.sql | mysql -u USERNAME -pPASSWORD -h HOST DBNAME
```

__※__ 백업 시, `^` 등 exec 함수에서 사용할 수 없는 문자의 경우, `^^` 등 escape 처리해서 사용

__※__ `database.ini` 내용 예시
```ini
   [backup01]
   host = localhost
   username = root
   password = root
   database[0] = db_name1
   database[1] = db_name2
   ignore-table[1] = table_name1,table_name2
```

__※__ `settings.ini` 내용 예시
```ini
   slack-url = "https://hooks.slack.com/services/*****/*****"
   localhost-username = "root"
   localhost-password = "root"
   localhost-host = "localhost"
```
