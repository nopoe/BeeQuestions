<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"]."/bq/base/BasePage.php";
require_once $_SERVER["DOCUMENT_ROOT"]."/bq/base/Validation.php";
$userId = ValidateAndReturnUserId(true);

$answer = trim($_POST["answer"]);
$tagstr = $_POST["tags"];
$tags = explode(" ", $tagstr);
$taglen = count($tags);
if($taglen > 10) {
	$taglen = 10;
	$tags = array_slice($tags, 0, 10);
} else if($taglen == 0) { ReturnError("Please enter one or more tags."); }
if($answer == "" || strlen($answer) > 400) { ReturnError("Please enter a valid answer (less than 400 characters)."); }

$sql = new SQLManager();
$userLevel = $sql->QueryVal("SELECT iLevel FROM bq_users WHERE cID = :user", ["user" => $userId]);
$allowedAs = $sql->QueryCount("SELECT iAnswersPerDay FROM bq_levels WHERE iLevel = :level", ["level" => $userLevel]);
$query = <<<EOT
SELECT COUNT(DISTINCT a.cID) AS answerCount
FROM bq_users u
	LEFT JOIN bq_answers a ON a.xUser = u.cID AND DATE_FORMAT(a.dtOpened, '%Y-%m-%d') = CURDATE()
WHERE u.cID = :user
GROUP BY u.cID
EOT;
$postedAs = $sql->QueryCount($query, ["user" => $userId]);
if(($allowedAs - $postedAs) <= 0) { ReturnError("You can't give any more answers today!"); }

$answerId = $sql->InsertAndReturn("INSERT INTO bq_answers (bnId, xUser, sAnswer, iStatus, iViews, iScore, dtStatusChanged, dtOpened) VALUES ($sql_bnID, :userId, :answer, 0, 0, 0, NOW(), NOW())", ["userId" => $userId, "answer" => $answer]);
if($answerId == null || $answerId <= 0) { ReturnError("An error occurred posting your answer! Please try again later!"); }
$answerHex = $sql->QueryVal("SELECT HEX(bnID) FROM bq_answers WHERE cID = :a", ["a" => $answerId]);

$insertQueries = [];
$insertVals = ["xAnswer" => $answerId];
for($i = 0; $i <= $taglen; $i++) {
	$lower = strtolower($tags[$i]);
	if($lower == "") { continue; }
	$id = $sql->QueryVal("SELECT cID FROM bq_tags WHERE sTag = :tag", ["tag" => $lower]);
	if(intval($id) == 0) { $id = $sql->InsertAndReturn("INSERT INTO bq_tags (sTag) VALUES (:tag)", ["tag" => $lower]); }
	$insertQueries[] = "(:xAnswer, :tag$i)";
	$insertVals["tag$i"] = $id;
}
$sql->Query("INSERT INTO bq_answers_tags_xref (xAnswer, xTag) VALUES ".implode(", ", $insertQueries), $insertVals);

$levelData = IncrementScore($sql, $userId, -5);
echo json_encode([
	"status" => true,
	"id" => Base64::to64($answerHex), 
	"pchange" => $levelData["pchange"],
	"lchange" => $levelData["lchange"], 
	"level" => $levelData["level"]
]);
?>