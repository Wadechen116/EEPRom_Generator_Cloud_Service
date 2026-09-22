# project/ — 更新套件的存放處

`api/update.php` 掃描這裡，回答「最新版是哪一個」。**沒有索引檔要維護**：版本寫在
檔名裡，上傳一個檔案就是發佈一個版本。

```
project/
  release/                     ← 正式版
    E2pRom_Generator/
      E2pRom_Generator-1.0.2-setup.exe    ← 套件本身
      E2pRom_Generator-1.0.2-setup.txt    ← 選用，更新說明（manifest 第 3 行）
      E2pRom_Generator-1.0.2-setup.exe.sha256   ← 自動產生，勿手動建立
      E2pRom_Generator-1.0.1-setup.exe    ← 舊版留著，可用 ?version= 回退
    SOICamConfig/
  debug/                       ← 內部測試版，結構相同
    E2pRom_Generator/
    SOICamConfig/
```

## 加一個新專案

建一個同名資料夾、把套件丟進去，**程式不用改**：

```
project/release/<新專案名>/<新專案名>-1.0.0-setup.exe
```

專案名只允許英數字與 `. _ -`，因為它會變成網址與路徑的一段。

## 檔名規則

```
<任意前綴>-<版號>[-setup].exe        或 .zip
```

版號是 1～4 段數字，例如 `1.0.2`、`2.1.0.3`。比較用數值，所以 `1.0.10` 比 `1.0.9` 新。
不符合這個樣式的檔案會被忽略——**放錯名字的套件不會被發佈，也不會報錯**，上傳後請
用 `?list=1` 確認它出現在清單裡。

## 該放 setup.exe 還是 zip

**放 setup.exe。** 安裝與更新用同一個產物，才不會發生「安裝包有的檔案、更新包漏了」
——DLL、`Configs\*.yaml` 都靠這點才不會與執行檔脫節。AppUpdater 看副檔名決定做法：
`.exe` 靜默安裝，`.zip` 解壓覆蓋程式目錄。

`.zip` 仍然支援，用於過渡：機器上還是舊版 AppUpdater 時，先發一版含新 AppUpdater 的
zip，之後才改發 setup.exe。

## 更新說明

跟套件同名、副檔名換成 `.txt`。內容會被壓成一行（manifest 是逐行的格式）。沒有這個
檔案時，第 3 行會是「專案名 + 版號」——**不能留空**，客戶端的解析器會跳過空行，
空的說明會讓第 4 行的 SHA-256 被當成說明讀走。

## .sha256

`api/update.php` 第一次被查詢時自動算並寫在旁邊，之後直接沿用；套件比它新就重算。
不要手動建立，也不要複製舊的。
