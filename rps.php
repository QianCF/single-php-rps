<?php
// 函数定义
function processRound($gameData, $playerId, $choice) {
    // 记录玩家选择
    $gameData["players"][$playerId]["choice"] = $choice;
    
    // 确保round_history数组存在
    if (!isset($gameData["round_history"])) {
        $gameData["round_history"] = [];
    }
    
    // 检查是否双方都已选择
    $allChosen = true;
    foreach ($gameData["players"] as $player) {
        if (!isset($player["choice"]) || $player["choice"] === null) {
            $allChosen = false;
            break;
        }
    }
    
    // 如果双方都已选择，判断胜负并扣血/回血
    if ($allChosen) {
        $player1Choice = $gameData["players"][1]["choice"];
        $player2Choice = $gameData["players"][2]["choice"];
        
        // 判断胜负
        $winner = determineWinner($player1Choice, $player2Choice);
        
        // 记录本轮结果
        $roundResult = [
            "round" => count($gameData["round_history"]) + 1,
            "player1_choice" => $player1Choice,
            "player2_choice" => $player2Choice,
            "winner" => $winner,
            "timestamp" => time()
        ];
        
        // 在历史记录开头插入新结果
        array_unshift($gameData["round_history"], $roundResult);
        
        // 伤害和回血逻辑
        if ($winner === 1) {
            $damage = getDamage($player1Choice, $player2Choice, $gameData["damage_settings"]);
            $healing = getHealing($player1Choice, $player2Choice, $gameData["healing_settings"]);
            $gameData["players"][2]["health"] -= $damage;
            $gameData["players"][1]["health"] += $healing;
        } elseif ($winner === 2) {
            $damage = getDamage($player2Choice, $player1Choice, $gameData["damage_settings"]);
            $healing = getHealing($player2Choice, $player1Choice, $gameData["healing_settings"]);
            $gameData["players"][1]["health"] -= $damage;
            $gameData["players"][2]["health"] += $healing;
        }
        
        // 检查游戏是否结束
        if ($gameData["players"][1]["health"] <= 0 || $gameData["players"][2]["health"] <= 0) {
            $gameData["game_phase"] = "ended";
            if ($gameData["players"][1]["health"] <= 0) {
                $gameData["winner"] = 2;
            } else {
                $gameData["winner"] = 1;
            }
        }
        
        // 重置选择状态，准备下一轮
        $gameData["players"][1]["choice"] = null;
        $gameData["players"][2]["choice"] = null;
    }
    
    return $gameData;
}

function determineWinner($choice1, $choice2) {
    if ($choice1 === $choice2) {
        return 0; // 平局
    }
    
    if (($choice1 === "rock" && $choice2 === "scissors") ||
        ($choice1 === "scissors" && $choice2 === "paper") ||
        ($choice1 === "paper" && $choice2 === "rock")) {
        return 1; // 玩家1胜利
    }
    
    return 2; // 玩家2胜利
}

function getDamage($winnerChoice, $loserChoice, $damageSettings) {
    if ($winnerChoice === "rock" && $loserChoice === "scissors") {
        return $damageSettings["rock_vs_scissors"];
    } elseif ($winnerChoice === "scissors" && $loserChoice === "paper") {
        return $damageSettings["scissors_vs_paper"];
    } elseif ($winnerChoice === "paper" && $loserChoice === "rock") {
        return $damageSettings["paper_vs_rock"];
    }
    return 0;
}

function getHealing($winnerChoice, $loserChoice, $healingSettings) {
    if ($winnerChoice === "rock" && $loserChoice === "scissors") {
        return $healingSettings["scissors_vs_rock"];
    } elseif ($winnerChoice === "scissors" && $loserChoice === "paper") {
        return $healingSettings["paper_vs_scissors"];
    } elseif ($winnerChoice === "paper" && $loserChoice === "rock") {
        return $healingSettings["rock_vs_paper"];
    }
    return 0;
}

// API 处理 - 必须在HTML输出之前
if (isset($_GET["api"])) {
    // 先处理查房接口：仅根据gameid返回各方是否已有人（是否已设密码）
    if ($_GET["api"] == "check" && isset($_GET["gameid"])) {
        if (!file_exists("rps2.json")) {
            file_put_contents("rps2.json", "[]");
        }
        $games = json_decode(file_get_contents("rps2.json"), true);
        $gameid = (int)$_GET["gameid"];
        header('Content-Type: application/json');
        if (isset($games[$gameid])) {
            $k = $games[$gameid];
            $sides = [];
            for ($i = 1; $i <= 2; $i++) {
                $sides[] = [
                    "side" => $i,
                    "hasPassword" => isset($k["players"][$i]["password"]) && $k["players"][$i]["password"] !== null
                ];
            }
            echo json_encode(["exists" => true, "sides" => $sides, "game_phase" => $k["game_phase"]]);
        } else {
            echo json_encode(["exists" => false]);
        }
        exit;
    }
    
    // 观战模式专用API - 不需要密码
    if ($_GET["api"] == "spectate" && isset($_GET["gameid"])) {
        if (!file_exists("rps2.json")) {
            file_put_contents("rps2.json", "[]");
        }
        $games = json_decode(file_get_contents("rps2.json"), true);
        $gameid = (int)$_GET["gameid"];
        header('Content-Type: application/json');
        
        if (isset($games[$gameid])) {
            $gameData = $games[$gameid];
            // 隐藏所有密码信息
            unset($gameData["players"][1]["password"]);
            unset($gameData["players"][2]["password"]);
            echo json_encode($gameData);
        } else {
            echo json_encode(["error" => "游戏不存在"]);
        }
        exit;
    }
    
    if (isset($_GET["gameid"]) && isset($_GET["password"]) && isset($_GET["side"])) {
        if (!file_exists("rps2.json")) {
            file_put_contents("rps2.json", "[]");
        }
        $games = json_decode(file_get_contents("rps2.json"), true);
        $gameid = (int)$_GET["gameid"];
        if (isset($games[(int)$_GET["gameid"]])) {
            $side = (int)$_GET["side"];
            $password = $games[$gameid]["players"][$side]["password"];
            if ($password != $_GET["password"]) {
                echo "错误，数据缺失或有误";
                exit;
            } else {
                if ($_GET["api"] == "get") {
                    $k = $games[$gameid];
                    
                    // 检查是否所有玩家都已加入，如果是则自动进入游戏阶段
                    if ($k["game_phase"] == "waiting") {
                        $allJoined = true;
                        for ($i = 1; $i <= 2; $i++) {
                            if ($k["players"][$i]["password"] === null) {
                                $allJoined = false;
                                break;
                            }
                        }
                        
                        if ($allJoined) {
                            // 所有玩家都已加入，进入游戏阶段
                            $k["game_phase"] = "playing";
                            $games[$gameid] = $k;
                            file_put_contents("rps2.json", json_encode($games));
                        }
                    }
                    
                    // 隐藏其他玩家的密码信息
                    foreach ($k["players"] as $playerId => $player) {
                        if ($playerId != $side) {
                            unset($k["players"][$playerId]["password"]);
                        }
                    }
                    header('Content-Type: application/json');
                    echo json_encode($k);
                    exit;
                }
                if ($_GET["api"] == "play") {
                    try {
                        if (!isset($games[$gameid]["wined"]) || !$games[$gameid]["wined"]) {
                            $choice = $_GET["choice"];
                            if (!in_array($choice, ["rock", "paper", "scissors"])) {
                                echo "选择无效！";
                                exit;
                            }
                            
                            // 执行出拳
                            $games[$gameid] = processRound($games[$gameid], $side, $choice);
                            
                            // 保存游戏数据
                            $jsonData = json_encode($games);
                            if ($jsonData === false) {
                                echo "保存游戏数据失败：JSON编码错误";
                                exit;
                            }
                            
                            $result = file_put_contents("rps2.json", $jsonData);
                            if ($result === false) {
                                echo "保存游戏数据失败：文件写入错误";
                                exit;
                            }
                            
                            echo "OK";
                        } else {
                            echo "游戏已经结束";
                        }
                    } catch (Exception $e) {
                        echo "操作时发生错误：" . $e->getMessage();
                    }
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <title><?="钱辰飞在线石头剪刀布游戏"?></title>

        <style>
            html, body
            {
                width: 100%;
                height: 100%;
            }
            a:link {color:#0033ff;}
            a:hover {color:#ffaa00;}
            a:active {color:#00ff00;}
        </style>

    </head>
    <body background="">
    <?php
    // 处理观战模式
    if (isset($_GET["spectate"]) && isset($_GET["gameid"])) {
        $gameid = (int)$_GET["gameid"];
        if (!file_exists("rps2.json")) {
            file_put_contents("rps2.json", "[]");
        }
        $games = json_decode(file_get_contents("rps2.json"), true);
        
        if (!isset($games[$gameid])) {
            // 游戏不存在，显示错误页面
            ?>
            <h1>游戏不存在</h1>
            <p>您要观战的游戏ID <?= $gameid ?> 不存在，请检查ID是否正确</p>
            <button onclick="location.href='rps.php'">返回首页</button>
            <?php
            exit;
        }
        
        $gameData = $games[$gameid];
        // 隐藏所有密码信息
        unset($gameData["players"][1]["password"]);
        unset($gameData["players"][2]["password"]);
        ?>
        <h1>石头剪刀布游戏 - 观战模式 - 游戏ID: <?= $gameid ?></h1>
        
        <div id="game-status">
            <span>游戏阶段: 
                <span id="phase-display">
                    <?php 
                    switch($gameData["game_phase"]) {
                        case "waiting": echo "等待玩家加入"; break;
                        case "playing": echo "进行中"; break;
                        case "ended": echo "已结束"; break;
                        default: echo $gameData["game_phase"];
                    }
                    ?>
                </span>
            </span>
        </div>
        
        <div id="health-display">
            <span>玩家1血量: <span id="player1-health"><?= $gameData["players"][1]["health"] ?></span></span>
            <span>玩家2血量: <span id="player2-health"><?= $gameData["players"][2]["health"] ?></span></span>
        </div>
        
        <div id="damage-display" style="margin: 10px 0;">
            <h4>伤害设置:</h4>
            <span>石头对剪刀: <span id="rock-vs-scissors-damage"><?= $gameData["damage_settings"]["rock_vs_scissors"] ?></span></span>
            <span>剪刀对布: <span id="scissors-vs-paper-damage"><?= $gameData["damage_settings"]["scissors_vs_paper"] ?></span></span>
            <span>布对石头: <span id="paper-vs-rock-damage"><?= $gameData["damage_settings"]["paper_vs_rock"] ?></span></span>
            <h4>回血设置:</h4>
            <span>剪刀给石头回血: <span id="scissors-vs-rock-healing"><?= $gameData["healing_settings"]["scissors_vs_rock"] ?></span></span>
            <span>布给剪刀回血: <span id="paper-vs-scissors-healing"><?= $gameData["healing_settings"]["paper_vs_scissors"] ?></span></span>
            <span>石头给布回血: <span id="rock-vs-paper-healing"><?= $gameData["healing_settings"]["rock_vs_paper"] ?></span></span>
            <h4>原总血量:</h4>
            <span id="all-health"><?= $gameData["initial_health"] ?? '计算中...' ?></span>
        </div>
        
        <div id="current-choices" style="margin: 10px 0;">
            <h4>当前选择:</h4>
            <span>玩家1: <span id="player1-choice"><?= $gameData["players"][1]["choice"] ? getChoiceText($gameData["players"][1]["choice"]) : "未选择" ?></span></span>
            <span>玩家2: <span id="player2-choice"><?= $gameData["players"][2]["choice"] ? getChoiceText($gameData["players"][2]["choice"]) : "未选择" ?></span></span>
        </div>
        
        <div id="spectator-message">
            <p><em>观战模式 - 您只能查看游戏状态，不能进行操作</em></p>
        </div>
        
        <div id="round-history">
            <h3>历史记录</h3>
            <table border="1">
                <tr>
                    <th>轮次</th>
                    <th>玩家1出</th>
                    <th>玩家2出</th>
                    <th>赢家</th>
                    <th>伤害</th>
                    <th>回血</th>
                </tr>
                <tbody id="history-body">
                    <?php
                    if (!empty($gameData["round_history"])) {
                        foreach ($gameData["round_history"] as $round) {
                            echo "<tr>";
                            echo "<td>{$round['round']}</td>";
                            echo "<td>" . getChoiceText($round['player1_choice']) . "</td>";
                            echo "<td>" . getChoiceText($round['player2_choice']) . "</td>";
                            echo "<td>" . ($round['winner'] == 0 ? "平局" : "玩家" . $round['winner']) . "</td>";
                            
                            // 计算伤害和回血
                            $damage = 0;
                            $healing = 0;
                            if ($round['winner'] != 0) {
                                $winnerChoice = $round['winner'] == 1 ? $round['player1_choice'] : $round['player2_choice'];
                                $loserChoice = $round['winner'] == 1 ? $round['player2_choice'] : $round['player1_choice'];
                                $damage = getDamageValue($winnerChoice, $loserChoice, $gameData["damage_settings"]);
                                $healing = getHealingValue($winnerChoice, $loserChoice, $gameData["healing_settings"]);
                            }
                            echo "<td>{$damage}</td>";
                            echo "<td>{$healing}</td>";
                            echo "</tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
        
        <button onclick="location.href='rps.php'">返回首页</button>
        
        <script>
            // 观战模式自动刷新
            function refreshSpectatorView() {
                fetch(`rps.php?api=spectate&gameid=<?= $gameid ?>`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error('观战错误:', data.error);
                            return;
                        }
                        
                        // 更新游戏阶段
                        const phaseDisplay = document.getElementById('phase-display');
                        switch(data.game_phase) {
                            case "waiting": phaseDisplay.textContent = "等待玩家加入"; break;
                            case "playing": phaseDisplay.textContent = "进行中"; break;
                            case "ended": phaseDisplay.textContent = "已结束,"+`玩家${data.winner}获胜!`; break;
                            default: phaseDisplay.textContent = data.game_phase;
                        }
                        
                        // 更新血量
                        document.getElementById('player1-health').textContent = data.players[1].health;
                        document.getElementById('player2-health').textContent = data.players[2].health;
                        
                        // 更新当前选择
                        document.getElementById('player1-choice').textContent = 
                            data.players[1].choice ? getChoiceText(data.players[1].choice) : "未选择";
                        document.getElementById('player2-choice').textContent = 
                            data.players[2].choice ? getChoiceText(data.players[2].choice) : "未选择";
                        
                        // 更新伤害和回血设置显示
                        if (data.damage_settings) {
                            document.getElementById('rock-vs-scissors-damage').textContent = data.damage_settings.rock_vs_scissors;
                            document.getElementById('scissors-vs-paper-damage').textContent = data.damage_settings.scissors_vs_paper;
                            document.getElementById('paper-vs-rock-damage').textContent = data.damage_settings.paper_vs_rock;
                        }
                        if (data.healing_settings) {
                            document.getElementById('scissors-vs-rock-healing').textContent = data.healing_settings.scissors_vs_rock;
                            document.getElementById('paper-vs-scissors-healing').textContent = data.healing_settings.paper_vs_scissors;
                            document.getElementById('rock-vs-paper-healing').textContent = data.healing_settings.rock_vs_paper;
                        }
                        
                        // 更新原总血量显示
                        const allHealthElement = document.getElementById('all-health');
                        if (data.initial_health) {
                            allHealthElement.textContent = data.initial_health;
                        } else {
                            // 如果没有记录初始血量，通过历史记录计算原总血量
                            let calculatedInitialHealth = data.players[1].health; // 从玩家1当前血量开始
                            
                            // 遍历历史记录，将每轮受到的伤害加回来，并将获得的回血减去
                            if (data.round_history && data.round_history.length > 0) {
                                data.round_history.forEach(round => {
                                    if (round.winner !== 0) { // 不是平局
                                        const winner = round.winner;
                                        const loser = winner === 1 ? 2 : 1;
                                        const winnerChoice = winner === 1 ? round.player1_choice : round.player2_choice;
                                        const loserChoice = loser === 1 ? round.player1_choice : round.player2_choice;
                                        
                                        // 计算伤害值
                                        let damage = 0;
                                        if (winnerChoice === "rock" && loserChoice === "scissors") {
                                            damage = data.damage_settings.rock_vs_scissors;
                                        } else if (winnerChoice === "scissors" && loserChoice === "paper") {
                                            damage = data.damage_settings.scissors_vs_paper;
                                        } else if (winnerChoice === "paper" && loserChoice === "rock") {
                                            damage = data.damage_settings.paper_vs_rock;
                                        }
                                        
                                        // 计算回血值
                                        let healing = 0;
                                        if (winnerChoice === "rock" && loserChoice === "scissors") {
                                            healing = data.healing_settings.scissors_vs_rock;
                                        } else if (winnerChoice === "scissors" && loserChoice === "paper") {
                                            healing = data.healing_settings.paper_vs_scissors;
                                        } else if (winnerChoice === "paper" && loserChoice === "rock") {
                                            healing = data.healing_settings.rock_vs_paper;
                                        }
                                        
                                        // 如果玩家1是输家，把受到的伤害加回来
                                        if (loser === 1 && damage > 0) {
                                            calculatedInitialHealth += damage;
                                        }
                                        // 如果玩家1是赢家，把获得的回血减去
                                        if (winner === 1 && healing > 0) {
                                            calculatedInitialHealth -= healing;
                                        }
                                    }
                                });
                            }
                            allHealthElement.textContent = calculatedInitialHealth;
                        }
                        
                        // 更新历史记录
                        updateHistory(data.round_history, data.damage_settings, data.healing_settings);
                    })
                    .catch(error => console.error('观战模式刷新错误:', error));
            }
            
            function getChoiceText(choice) {
                const choices = {
                    "rock": "石头",
                    "paper": "布", 
                    "scissors": "剪刀"
                };
                return choices[choice] || choice;
            }
            
            function getDamageValue(winnerChoice, loserChoice, damageSettings) {
                if (winnerChoice === "rock" && loserChoice === "scissors") {
                    return damageSettings.rock_vs_scissors;
                } else if (winnerChoice === "scissors" && loserChoice === "paper") {
                    return damageSettings.scissors_vs_paper;
                } else if (winnerChoice === "paper" && loserChoice === "rock") {
                    return damageSettings.paper_vs_rock;
                }
                return 0;
            }
            
            function getHealingValue(winnerChoice, loserChoice, healingSettings) {
                if (winnerChoice === "rock" && loserChoice === "scissors") {
                    return healingSettings.scissors_vs_rock;
                } else if (winnerChoice === "scissors" && loserChoice === "paper") {
                    return healingSettings.paper_vs_scissors;
                } else if (winnerChoice === "paper" && loserChoice === "rock") {
                    return healingSettings.rock_vs_paper;
                }
                return 0;
            }
            
            function updateHistory(history, damageSettings, healingSettings) {
                const historyBody = document.getElementById('history-body');
                historyBody.innerHTML = '';
                
                history.forEach(round => {
                    const row = document.createElement('tr');
                    
                    const roundCell = document.createElement('td');
                    roundCell.textContent = round.round;
                    row.appendChild(roundCell);
                    
                    const p1ChoiceCell = document.createElement('td');
                    p1ChoiceCell.textContent = getChoiceText(round.player1_choice);
                    row.appendChild(p1ChoiceCell);
                    
                    const p2ChoiceCell = document.createElement('td');
                    p2ChoiceCell.textContent = getChoiceText(round.player2_choice);
                    row.appendChild(p2ChoiceCell);
                    
                    const winnerCell = document.createElement('td');
                    winnerCell.textContent = round.winner === 0 ? "平局" : "玩家" + round.winner;
                    row.appendChild(winnerCell);
                    
                    const damageCell = document.createElement('td');
                    const healingCell = document.createElement('td');
                    if (round.winner !== 0) {
                        const winnerChoice = round.winner === 1 ? round.player1_choice : round.player2_choice;
                        const loserChoice = round.winner === 1 ? round.player2_choice : round.player1_choice;
                        const damage = getDamageValue(winnerChoice, loserChoice, damageSettings);
                        const healing = getHealingValue(winnerChoice, loserChoice, healingSettings);
                        damageCell.textContent = damage;
                        healingCell.textContent = healing;
                    } else {
                        damageCell.textContent = "0";
                        healingCell.textContent = "0";
                    }
                    row.appendChild(damageCell);
                    row.appendChild(healingCell);
                    
                    historyBody.appendChild(row);
                });
            }
            refreshSpectatorView();
            // 每1秒刷新一次
            setInterval(refreshSpectatorView, 1000);
        </script>
        
        <?php
        exit;
    }
    
    if ((!isset($_GET["gameid"]) || !isset($_GET["password"]) || !isset($_GET["side"]))) {
        if (!isset($_POST["new"])) {
        ?>
            <h1>石头剪刀布游戏</h1>
            
            <div>
                <h2>新建游戏</h2>
                <form method="post" id="create-form">
                    <div>
                        <label for="pwdset">设置您方当局密码 (字母和数字，最长10位):</label>
                        <input type="text" id="pwdset" name="pwdset" pattern="^[a-zA-Z0-9]{1,10}$" required />
                    </div>
                    <div>
                        <label for="health">设置血量 (大于0的整数):</label>
                        <input type="number" id="health" name="health" min="1" value="10" required />
                    </div>
                    <div>
                        <h4>伤害设置 (输方扣除):</h4>
                        <label for="rock_vs_scissors">石头对剪刀伤害值:</label>
                        <input type="number" id="rock_vs_scissors" name="rock_vs_scissors" value="2" />
                    </div>
                    <div>
                        <label for="scissors_vs_paper">剪刀对布伤害值:</label>
                        <input type="number" id="scissors_vs_paper" name="scissors_vs_paper" value="2" />
                    </div>
                    <div>
                        <label for="paper_vs_rock">布对石头伤害值:</label>
                        <input type="number" id="paper_vs_rock" name="paper_vs_rock" value="2" />
                    </div>
                    <div>
                        <h4>回血设置 (赢方获得):</h4>
                        <label for="scissors_vs_rock">剪刀给石头回血值:</label>
                        <input type="number" id="scissors_vs_rock" name="scissors_vs_rock" value="0" />
                    </div>
                    <div>
                        <label for="paper_vs_scissors">布给剪刀回血值:</label>
                        <input type="number" id="paper_vs_scissors" name="paper_vs_scissors" value="0" />
                    </div>
                    <div>
                        <label for="rock_vs_paper">石头给布回血值:</label>
                        <input type="number" id="rock_vs_paper" name="rock_vs_paper" value="0" />
                    </div>
                    <div>
                        <label for="my_side">选择你的玩家号:</label>
                        <select id="my_side" name="my_side" required>
                            <option value="1">玩家1</option>
                            <option value="2">玩家2</option>
                        </select>
                    </div>
                    <button type="submit" name="new">创建新游戏</button>
                </form>
            </div>

            <div>
                <h2>加入游戏</h2>
                <form method="get" id="join-form" onsubmit="return validateJoinForm()">
                    <div>
                        <label for="gameid">游戏 ID:</label>
                        <input type="number" id="gameid" name="gameid" required />
                        <button type="button" id="check-game-btn">查询</button>
                    </div>
                    <div id="check-result"></div>

                    <div id="side-group" style="display:none;">
                        <label>选择您的玩家位置:</label>
                        <div id="side-list"></div>
                    </div>

                    <div id="pwd-group" style="display:none;">
                        <label for="password">密码:</label>
                        <input type="text" id="password" name="password" pattern="^[a-zA-Z0-9]{1,10}$" />
                    </div>

                    <p id="join-hint" style="display:none;">
                        如果你是第一次加入这个游戏，输入以设置你方的密码，请记住您输入的密码。
                        如果你曾经设置过这个游戏你方的密码，则需要验证已有密码以加入。
                    </p>

                    <input type="hidden" name="side" id="hidden-side" value="">
                    <div id="submit-wrap" style="display:none;">
                        <button type="submit" id="join-submit-btn">加入游戏</button>
                    </div>
                </form>
            </div>

            <div>
                <h2>观战游戏</h2>
                <form method="get" id="spectate-form" onsubmit="return validateSpectateForm()">
                    <div>
                        <label for="spectate-gameid">游戏 ID:</label>
                        <input type="number" id="spectate-gameid" name="gameid" required />
                        <button type="button" id="check-spectate-btn">查询</button>
                        <input type="hidden" name="spectate" value="1" />
                    </div>
                    <div id="spectate-check-result"></div>
                    <div id="spectate-submit-wrap" style="display:none;">
                        <button type="submit">进入观战</button>
                    </div>
                </form>
            </div>

            <script>
                let currentGameExists = false;
                let currentGameId = null;
                
                // 加入游戏 - 先查房后显示密码与位置
                const checkBtn = document.getElementById('check-game-btn');
                const gameIdInput = document.getElementById('gameid');
                const resultDiv = document.getElementById('check-result');
                const sideGroup = document.getElementById('side-group');
                const sideList = document.getElementById('side-list');
                const pwdGroup = document.getElementById('pwd-group');
                const joinHint = document.getElementById('join-hint');
                const submitWrap = document.getElementById('submit-wrap');
                const hiddenSide = document.getElementById('hidden-side');
                const joinForm = document.getElementById('join-form');
                
                // 观战游戏
                const checkSpectateBtn = document.getElementById('check-spectate-btn');
                const spectateGameIdInput = document.getElementById('spectate-gameid');
                const spectateResultDiv = document.getElementById('spectate-check-result');
                const spectateSubmitWrap = document.getElementById('spectate-submit-wrap');
                const spectateForm = document.getElementById('spectate-form');
                
                function validateJoinForm() {
                    if (!currentGameExists || currentGameId !== parseInt(gameIdInput.value)) {
                        alert('请先查询游戏是否存在');
                        return false;
                    }
                    return true;
                }
                
                function validateSpectateForm() {
                    if (!currentGameExists || currentGameId !== parseInt(spectateGameIdInput.value)) {
                        alert('请先查询游戏是否存在');
                        return false;
                    }
                    return true;
                }
                
                if (checkBtn) {
                    checkBtn.addEventListener('click', () => {
                        const gid = parseInt(gameIdInput.value, 10);
                        if (isNaN(gid)) {
                            resultDiv.style.color = 'red';
                            resultDiv.textContent = '请输入有效的游戏ID';
                            return;
                        }
                        resultDiv.textContent = '查询中...';
                        fetch(`rps.php?api=check&gameid=${gid}`)
                            .then(r => r.json())
                            .then(data => {
                                if (!data.exists) {
                                    resultDiv.style.color = 'red';
                                    resultDiv.textContent = '游戏不存在';
                                    sideGroup.style.display = 'none';
                                    pwdGroup.style.display = 'none';
                                    joinHint.style.display = 'none';
                                    submitWrap.style.display = 'none';
                                    hiddenSide.value = '';
                                    currentGameExists = false;
                                    return;
                                }
                                currentGameExists = true;
                                currentGameId = gid;
                                resultDiv.style.color = 'green';
                                resultDiv.textContent = '游戏存在，请选择一方并输入密码';
                                // 渲染每行一个的位置
                                sideList.innerHTML = '';
                                data.sides.forEach(s => {
                                    const line = document.createElement('label');
                                    line.style.display = 'block';
                                    line.style.margin = '6px 0';
                                    const radio = document.createElement('input');
                                    radio.type = 'radio';
                                    radio.name = 'side-radio';
                                    radio.value = String(s.side);
                                    radio.addEventListener('change', () => {
                                        hiddenSide.value = String(s.side);
                                    });
                                    const text = document.createElement('span');
                                    text.textContent = ` 玩家${s.side} - ` + (s.hasPassword ? '已有人' : '无人');
                                    line.appendChild(radio);
                                    line.appendChild(text);
                                    sideList.appendChild(line);
                                });
                                // 默认选第一个
                                const first = sideList.querySelector('input[type=radio]');
                                if (first) {
                                    first.checked = true;
                                    hiddenSide.value = first.value;
                                }
                                sideGroup.style.display = 'block';
                                pwdGroup.style.display = 'block';
                                joinHint.style.display = 'block';
                                submitWrap.style.display = 'block';
                            })
                            .catch(() => {
                                resultDiv.style.color = 'red';
                                resultDiv.textContent = '查询失败，请重试';
                                currentGameExists = false;
                            });
                    });
                }
                
                if (checkSpectateBtn) {
                    checkSpectateBtn.addEventListener('click', () => {
                        const gid = parseInt(spectateGameIdInput.value, 10);
                        if (isNaN(gid)) {
                            spectateResultDiv.style.color = 'red';
                            spectateResultDiv.textContent = '请输入有效的游戏ID';
                            return;
                        }
                        spectateResultDiv.textContent = '查询中...';
                        fetch(`rps.php?api=check&gameid=${gid}`)
                            .then(r => r.json())
                            .then(data => {
                                if (!data.exists) {
                                    spectateResultDiv.style.color = 'red';
                                    spectateResultDiv.textContent = '游戏不存在';
                                    spectateSubmitWrap.style.display = 'none';
                                    currentGameExists = false;
                                    return;
                                }
                                currentGameExists = true;
                                currentGameId = gid;
                                spectateResultDiv.style.color = 'green';
                                spectateResultDiv.textContent = `游戏存在，当前阶段: ${
                                    data.game_phase === 'waiting' ? '等待玩家加入' : 
                                    data.game_phase === 'playing' ? '进行中' : 
                                    data.game_phase === 'ended' ? '已结束' : data.game_phase
                                }`;
                                spectateSubmitWrap.style.display = 'block';
                            })
                            .catch(() => {
                                spectateResultDiv.style.color = 'red';
                                spectateResultDiv.textContent = '查询失败，请重试';
                                currentGameExists = false;
                            });
                    });
                }
                
                // 防止表单直接提交
                joinForm.addEventListener('submit', function(e) {
                    if (!validateJoinForm()) {
                        e.preventDefault();
                    }
                });
                
                spectateForm.addEventListener('submit', function(e) {
                    if (!validateSpectateForm()) {
                        e.preventDefault();
                    }
                });
            </script>
        <?php
        } else {
            if (!file_exists("rps2.json")) {
                file_put_contents("rps2.json", "[]");
            }
            $games = json_decode(file_get_contents("rps2.json"), true);
            $cc = count($games);
            
            // 验证血量
            $health = isset($_POST["health"]) ? (int)$_POST["health"] : 10;
            if ($health < 1) { $health = 10; }
            $mySide = isset($_POST["my_side"]) ? (int)$_POST["my_side"] : 1;
            if ($mySide < 1 || $mySide > 2) { $mySide = 1; }

            // 获取伤害设置
            $rockVsScissors = isset($_POST["rock_vs_scissors"]) ? (int)$_POST["rock_vs_scissors"] : 2;
            $scissorsVsPaper = isset($_POST["scissors_vs_paper"]) ? (int)$_POST["scissors_vs_paper"] : 2;
            $paperVsRock = isset($_POST["paper_vs_rock"]) ? (int)$_POST["paper_vs_rock"] : 2;

            // 获取回血设置
            $scissorsVsRock = isset($_POST["scissors_vs_rock"]) ? (int)$_POST["scissors_vs_rock"] : 0;
            $paperVsScissors = isset($_POST["paper_vs_scissors"]) ? (int)$_POST["paper_vs_scissors"] : 0;
            $rockVsPaper = isset($_POST["rock_vs_paper"]) ? (int)$_POST["rock_vs_paper"] : 0;

            // 初始游戏状态
            $playersInit = [];
            for ($i = 1; $i <= 2; $i++) {
                $playersInit[$i] = [
                    "password" => ($i === $mySide ? $_POST["pwdset"] : null), 
                    "health" => $health,
                    "choice" => null
                ];
            }
            $initialGame = [
                "players" => $playersInit,
                "game_phase" => "waiting",
                "round_history" => [],
                "wined" => false,
                "winner" => null,
                "damage_settings" => [
                    "rock_vs_scissors" => $rockVsScissors,
                    "scissors_vs_paper" => $scissorsVsPaper,
                    "paper_vs_rock" => $paperVsRock
                ],
                "healing_settings" => [
                    "scissors_vs_rock" => $scissorsVsRock,
                    "paper_vs_scissors" => $paperVsScissors,
                    "rock_vs_paper" => $rockVsPaper
                ],
                "initial_health" => $health
            ];
            
            $games[$cc] = $initialGame;
            $games = json_encode($games);
            file_put_contents("rps2.json", $games);
        ?>
            <h1>游戏创建成功！</h1>
            <p>您的游戏ID是: <strong><?= $cc ?></strong></p>
            <p>您是: <strong>玩家<?= $mySide ?></strong></p>
            <p>初始血量: <strong><?= $health ?></strong></p>
            <p>伤害设置 (输方扣除):</p>
            <ul>
                <li>石头对剪刀: <strong><?= $rockVsScissors ?></strong></li>
                <li>剪刀对布: <strong><?= $scissorsVsPaper ?></strong></li>
                <li>布对石头: <strong><?= $paperVsRock ?></strong></li>
            </ul>
            <p>回血设置 (赢方获得):</p>
            <ul>
                <li>剪刀给石头回血: <strong><?= $scissorsVsRock ?></strong></li>
                <li>布给剪刀回血: <strong><?= $paperVsScissors ?></strong></li>
                <li>石头给布回血: <strong><?= $rockVsPaper ?></strong></li>
            </ul>
            <p>您的密码是: <strong><?= $_POST["pwdset"] ?></strong></p>
            <p>请妥善保管密码。</p>
            <div>
                <a href="rps.php?gameid=<?= $cc ?>&side=<?= $mySide ?>&password=<?= $_POST["pwdset"] ?>">
                    <button>进入游戏</button>
                </a>
            </div>
            <button onclick="history.go(-1)">返回首页</button>
        <?php
        }
    } elseif (isset($_GET["gameid"]) && isset($_GET["password"]) && isset($_GET["side"])) {
        if (!file_exists("rps2.json")) {
            file_put_contents("rps2.json", "[]");
        }
        $games = json_decode(file_get_contents("rps2.json"), true);
        $gameid = (int)$_GET["gameid"];
        if (isset($games[(int)$_GET["gameid"]])) {
            $side = (int)$_GET["side"];
            $password = $games[$gameid]["players"][$side]["password"];
            if ($side < 1 || $side > 2) {
                ?>
                <h1>玩家号不合法</h1>
                <button onclick="location.href='rps.php'">返回首页</button>
                <?php
                exit;
            }
            if (is_null($password)) {
                $password = $_GET["password"];
                $games[$gameid]["players"][$side]["password"] = $_GET["password"];
                file_put_contents("rps2.json", json_encode($games));
            }
            
            if ($password != $_GET["password"]) {
            ?>
                <h1>密码错误</h1>
                <p>你输错密码了，这可能是因为你选择了错误或不属于你的一方</p>
                <button onclick="location.href='rps.php'">返回重试</button>
            <?php
            } else {
            ?>
                <h1>石头剪刀布游戏 - 游戏ID: <?= $gameid ?> - 玩家<?= $side ?></h1>
                
                <div id="game-status">
                    <span>游戏阶段: <span id="phase-display">加载中...</span></span>
                </div>
                
                <div id="health-display">
                    <span>玩家1血量: <span id="player1-health">-</span></span>
                    <span>玩家2血量: <span id="player2-health">-</span></span>
                </div>
                
                <div id="damage-display" style="margin: 10px 0;">
                    <h4>伤害设置 (输方扣除):</h4>
                    <span>石头对剪刀: <span id="rock-vs-scissors-damage">-</span></span>
                    <span>剪刀对布: <span id="scissors-vs-paper-damage">-</span></span>
                    <span>布对石头: <span id="paper-vs-rock-damage">-</span></span>
                    <h4>回血设置 (赢方获得):</h4>
                    <span>剪刀给石头回血: <span id="scissors-vs-rock-healing">-</span></span>
                    <span>布给剪刀回血: <span id="paper-vs-scissors-healing">-</span></span>
                    <span>石头给布回血: <span id="rock-vs-paper-healing">-</span></span>
                    <h4>原总血量:</h4>
                    <span id="all-health">-</span>
                </div>
                
                <div id="choice-buttons" style="display:none;">
                    <h3>选择你的出拳:</h3>
                    <button onclick="playChoice('rock')">石头</button>
                    <button onclick="playChoice('paper')">布</button>
                    <button onclick="playChoice('scissors')">剪刀</button>
                </div>
                
                <div id="waiting-message" style="display:none;">
                    <span>等待对方选择...</span>
                </div>
                
                <div id="round-history">
                    <h3>历史记录</h3>
                    <table border="1">
                        <tr>
                            <th>轮次</th>
                            <th>你方出</th>
                            <th>对方出</th>
                            <th>赢家</th>
                            <th>伤害</th>
                            <th>回血</th>
                        </tr>
                        <tbody id="history-body"></tbody>
                    </table>
                </div>
                
                <button onclick="location.href='rps.php'">返回首页</button>

                <script>
                    // 游戏配置
                    const gameId = <?= $gameid ?>;
                    const playerSide = <?= (int)$_GET["side"] ?>;
                    const playerPassword = "<?= $_GET["password"] ?>";
                    let lastGameData = null;

                    // DOM元素
                    const gameStatus = document.getElementById('gg');
                    const phaseDisplay = document.getElementById('phase-display');
                    const player1Health = document.getElementById('player1-health');
                    const player2Health = document.getElementById('player2-health');
                    const rockVsScissorsDamage = document.getElementById('rock-vs-scissors-damage');
                    const scissorsVsPaperDamage = document.getElementById('scissors-vs-paper-damage');
                    const paperVsRockDamage = document.getElementById('paper-vs-rock-damage');
                    const scissorsVsRockHealing = document.getElementById('scissors-vs-rock-healing');
                    const paperVsScissorsHealing = document.getElementById('paper-vs-scissors-healing');
                    const rockVsPaperHealing = document.getElementById('rock-vs-paper-healing');
                    const choiceButtons = document.getElementById('choice-buttons');
                    const waitingMessage = document.getElementById('waiting-message');
                    const historyBody = document.getElementById('history-body');

                    // 更新游戏状态
                    function updateGame(gameData) {
                        // 更新游戏阶段显示
                        switch(gameData.game_phase) {
                            case "waiting": 
                                phaseDisplay.textContent = "等待玩家加入";
                                break;
                            case "playing": 
                                phaseDisplay.textContent = "进行中";
                                break;
                            case "ended": 
                                phaseDisplay.textContent = "已结束,"+`玩家${gameData.winner}获胜!`;
                                break;
                            default: 
                                phaseDisplay.textContent = gameData.game_phase;
                        }
                        
                        // 更新血量
                        player1Health.textContent = gameData.players[1].health;
                        player2Health.textContent = gameData.players[2].health;
                        
                        // 更新伤害和回血设置显示
                        if (gameData.damage_settings) {
                            rockVsScissorsDamage.textContent = gameData.damage_settings.rock_vs_scissors;
                            scissorsVsPaperDamage.textContent = gameData.damage_settings.scissors_vs_paper;
                            paperVsRockDamage.textContent = gameData.damage_settings.paper_vs_rock;
                        }
                        if (gameData.healing_settings) {
                            scissorsVsRockHealing.textContent = gameData.healing_settings.scissors_vs_rock;
                            paperVsScissorsHealing.textContent = gameData.healing_settings.paper_vs_scissors;
                            rockVsPaperHealing.textContent = gameData.healing_settings.rock_vs_paper;
                        }
                        
                        // 更新原总血量显示 - 保留加成计算器
                        const allHealthElement = document.getElementById('all-health');
                        if (gameData.initial_health) {
                            allHealthElement.textContent = gameData.initial_health;
                        } else {
                            // 如果没有记录初始血量，通过历史记录计算原总血量
                            let calculatedInitialHealth = gameData.players[playerSide].health;
                            
                            if (gameData.round_history && gameData.round_history.length > 0) {
                                gameData.round_history.forEach(round => {
                                    if (round.winner !== 0) {
                                        const winner = round.winner;
                                        const loser = winner === 1 ? 2 : 1;
                                        const winnerChoice = winner === 1 ? round.player1_choice : round.player2_choice;
                                        const loserChoice = loser === 1 ? round.player1_choice : round.player2_choice;
                                        
                                        let damage = 0;
                                        if (winnerChoice === "rock" && loserChoice === "scissors") {
                                            damage = gameData.damage_settings.rock_vs_scissors;
                                        } else if (winnerChoice === "scissors" && loserChoice === "paper") {
                                            damage = gameData.damage_settings.scissors_vs_paper;
                                        } else if (winnerChoice === "paper" && loserChoice === "rock") {
                                            damage = gameData.damage_settings.paper_vs_rock;
                                        }
                                        
                                        let healing = 0;
                                        if (winnerChoice === "rock" && loserChoice === "scissors") {
                                            healing = gameData.healing_settings.scissors_vs_rock;
                                        } else if (winnerChoice === "scissors" && loserChoice === "paper") {
                                            healing = gameData.healing_settings.paper_vs_scissors;
                                        } else if (winnerChoice === "paper" && loserChoice === "rock") {
                                            healing = gameData.healing_settings.rock_vs_paper;
                                        }
                                        
                                        // 如果当前玩家是输家，把受到的伤害加回来
                                        if (loser === playerSide && damage > 0) {
                                            calculatedInitialHealth += damage;
                                        }
                                        // 如果当前玩家是赢家，把获得的回血减去
                                        if (winner === playerSide && healing > 0) {
                                            calculatedInitialHealth -= healing;
                                        }
                                    }
                                });
                            }
                            allHealthElement.textContent = calculatedInitialHealth;
                        }
                        if (gameData.game_phase === "ended") {
                            choiceButtons.style.display = 'none';
                            waitingMessage.style.display = 'none';
                        } else {
                            // 检查当前选择状态
                            const myChoice = gameData.players[playerSide].choice;
                            const opponentChoice = gameData.players[playerSide === 1 ? 2 : 1].choice;
                            
                            if (myChoice === null) {
                                choiceButtons.style.display = 'block';
                                waitingMessage.style.display = 'none';
                            } else if (opponentChoice === null) {
                                // 我已选择，等待对方
                                waitingMessage.innerHTML = "已选择，等待对方...";
                                choiceButtons.style.display = 'none';
                                waitingMessage.style.display = 'block';
                            } else {
                                choiceButtons.style.display = 'block';
                                waitingMessage.style.display = 'none';
                            }
                        }
                        
                        
                        // 更新历史记录
                        updateHistory(gameData.round_history, gameData.damage_settings, gameData.healing_settings);
                    }

                    // 更新历史记录
                    function updateHistory(history, damageSettings, healingSettings) {
                        historyBody.innerHTML = '';
                        history.forEach(round => {
                            const row = document.createElement('tr');
                            
                            const roundCell = document.createElement('td');
                            roundCell.textContent = round.round;
                            row.appendChild(roundCell);
                            
                            const myChoiceCell = document.createElement('td');
                            myChoiceCell.textContent = getChoiceText(playerSide === 1 ? round.player1_choice : round.player2_choice);
                            row.appendChild(myChoiceCell);
                            
                            const opponentChoiceCell = document.createElement('td');
                            opponentChoiceCell.textContent = getChoiceText(playerSide === 1 ? round.player2_choice : round.player1_choice);
                            row.appendChild(opponentChoiceCell);
                            
                            const winnerCell = document.createElement('td');
                            if (round.winner === 0) {
                                winnerCell.textContent = "平局";
                            } else if (round.winner === playerSide) {
                                winnerCell.textContent = "你赢";
                            } else {
                                winnerCell.textContent = "对方赢";
                            }
                            row.appendChild(winnerCell);
                            
                            // 添加伤害和回血列
                            const damageCell = document.createElement('td');
                            const healingCell = document.createElement('td');
                            if (round.winner !== 0) {
                                const winnerChoice = round.winner === 1 ? round.player1_choice : round.player2_choice;
                                const loserChoice = round.winner === 1 ? round.player2_choice : round.player1_choice;
                                const damage = getDamageValue(winnerChoice, loserChoice, damageSettings);
                                const healing = getHealingValue(winnerChoice, loserChoice, healingSettings);
                                damageCell.textContent = damage;
                                healingCell.textContent = healing;
                            } else {
                                damageCell.textContent = "0";
                                healingCell.textContent = "0";
                            }
                            row.appendChild(damageCell);
                            row.appendChild(healingCell);
                            
                            historyBody.appendChild(row);
                        });
                    }

                    // 获取选择文本
                    function getChoiceText(choice) {
                        const choices = {
                            "rock": "石头",
                            "paper": "布", 
                            "scissors": "剪刀"
                        };
                        return choices[choice] || choice;
                    }

                    // 获取伤害值
                    function getDamageValue(winnerChoice, loserChoice, damageSettings) {
                        if (winnerChoice === "rock" && loserChoice === "scissors") {
                            return damageSettings.rock_vs_scissors;
                        } else if (winnerChoice === "scissors" && loserChoice === "paper") {
                            return damageSettings.scissors_vs_paper;
                        } else if (winnerChoice === "paper" && loserChoice === "rock") {
                            return damageSettings.paper_vs_rock;
                        }
                        return 0;
                    }

                    // 获取回血值
                    function getHealingValue(winnerChoice, loserChoice, healingSettings) {
                        if (winnerChoice === "rock" && loserChoice === "scissors") {
                            return healingSettings.scissors_vs_rock;
                        } else if (winnerChoice === "scissors" && loserChoice === "paper") {
                            return healingSettings.paper_vs_scissors;
                        } else if (winnerChoice === "paper" && loserChoice === "rock") {
                            return healingSettings.rock_vs_paper;
                        }
                        return 0;
                    }

                    // 处理出拳选择
                    function playChoice(choice) {
                        fetch(`rps.php?api=play&gameid=${gameId}&side=${playerSide}&password=${playerPassword}&choice=${choice}`)
                            .then(response => response.text())
                            .then(result => {
                                if (result === "OK") {
                                    fetchGameData();
                                } else {
                                    alert('出拳失败: ' + result);
                                }
                            })
                            .catch(error => {
                                console.error('出拳错误:', error);
                                alert('出拳时发生错误: ' + error);
                            });
                    }

                    // 获取游戏数据
                    function fetchGameData() {
                        fetch(`rps.php?api=get&gameid=${gameId}&side=${playerSide}&password=${playerPassword}`)
                            .then(response => response.json())
                            .then(data => {
                                // 只在数据变化时更新
                                if (JSON.stringify(data) !== JSON.stringify(lastGameData)) {
                                    lastGameData = data;
                                    updateGame(data);
                                }
                            })
                            .catch(error => {
                                console.error('获取游戏数据错误:', error);
                            });
                    }

                    // 初始化
                    fetchGameData();

                    // 设置自动刷新
                    setInterval(fetchGameData, 1000);
                </script>
            <?php
            }
        } else {
            ?>
            <h1>游戏不存在</h1>
            <p>您输入的游戏ID不存在，请检查ID是否正确</p>
            <button onclick="location.href='rps.php'">返回首页</button>
        <?php
        }
    }
    ?>
    
    <div>
        <h3>游戏说明：</h3>
        <ul>
            <li>支持2名玩家参与</li>
            <li>创建游戏时可以设置血量（大于1的整数），默认10血</li>
            <li>可以设置不同出拳组合的伤害值（可以是负数）</li>
            <li>可以设置不同出拳组合的回血值（可以是负数）</li>
            <li>每轮根据胜负结果：输方扣除伤害值，赢方获得回血值，平局不扣血不回血</li>
            <li>先显示等待两方选择，一方出后禁用那方的按钮</li>
            <li>都选择后显示历史记录并扣血/回血</li>
            <li>任一玩家血量为0或以下时游戏结束</li>
            <li>新增观战模式，可以查看游戏状态但不能操作</li>
        </ul>
    </div>
</body>
</html>

<?php
// 辅助函数
function getChoiceText($choice) {
    $choices = [
        "rock" => "石头",
        "paper" => "布", 
        "scissors" => "剪刀"
    ];
    return $choices[$choice] ?? $choice;
}

function getDamageValue($winnerChoice, $loserChoice, $damageSettings) {
    if ($winnerChoice === "rock" && $loserChoice === "scissors") {
        return $damageSettings["rock_vs_scissors"];
    } else if ($winnerChoice === "scissors" && $loserChoice === "paper") {
        return $damageSettings["scissors_vs_paper"];
    } else if ($winnerChoice === "paper" && $loserChoice === "rock") {
        return $damageSettings["paper_vs_rock"];
    }
    return 0;
}

function getHealingValue($winnerChoice, $loserChoice, $healingSettings) {
    if ($winnerChoice === "rock" && $loserChoice === "scissors") {
        return $healingSettings["scissors_vs_rock"];
    } else if ($winnerChoice === "scissors" && $loserChoice === "paper") {
        return $healingSettings["paper_vs_scissors"];
    } else if ($winnerChoice === "paper" && $loserChoice === "rock") {
        return $healingSettings["rock_vs_paper"];
    }
    return 0;
}
?>