# History-in-Today 数据集

发生在“历史上的今天”的  
**事件（events）｜出生（births）｜逝世（deaths）**，数据来自中文维基百科。

## 文件说明

| 文件名 | 格式 | 体积 | 用途 |
|----|----|----|----|
| `history_in_today.json` | JSON | ≈ 4 MB | 开发/前端直接引用 |
| `history_in_today.sql` | MySQL dump | ≈ 7 MB | 快速导入 MySQL/MariaDB |
| `history_in_today.sqlite` | SQLite 3 | ≈ 5 MB | 本地嵌入式数据库 |
| `history_in_today.csv` | CSV | ≈ 4 MB | Excel、Pandas 一键读取 |

所有文件均使用 **UTF-8 编码**，时间均以 **公历** 为准。

## 时间跨度

- **起始**：人类文明出现“纪日”概念的可考年代（公元前 3000 年级别）  
- **截止**：昨天（数据集每日自动更新，含最新一天）  
- **闰年 2-29** 已单独收录，共 366 条 `mm-dd` 记录，**公历日日齐全**
具体得看维基百科最早的记录了，我也不太清楚，因为都是`JaneDevStudioBot/2.0 (+https://github.com/JaneDevStudio) master@zeapi.ink`这个爬虫爬的。

> 注：脚本里仅借用 2000 年（闰年）的日历模板来生成 366 个日期占位符，实际内容按真实年份抓取，与 2000 年无关。

## 快速开始

1. 进入 [Releases](https://github.com/JaneDevStudio/History-in-Today-Data-set/releases) 页面
2. 下载最新版 你所需要的数据集格式
3. 按你的场景使用：

### JSON（最通用）
```python
import json, datetime
today = datetime.datetime.today().strftime("%m-%d")
data = json.load(open("history_in_today.json"))
print("今天事件：", data[today]["events"])
```

MySQL

```sql
mysql> source history_in_today.sql
-- 表结构：history_in_today(id, monthday, type, content)
SELECT content FROM history_in_today
WHERE monthday = DATE_FORMAT(CURDATE(), '%m-%d') AND type = 'events';
```

SQLite

```bash
sqlite3 today.db ".read history_in_today.sql"
sqlite3 today.db "SELECT content FROM history_in_today \
                  WHERE monthday = strftime('%m-%d', 'now') \
                  AND type = 'births';"
```

CSV

```python
import pandas as pd
df = pd.read_csv("history_in_today.csv", sep="|")
today = pd.Timestamp.now().strftime("%m-%d")
print(df[(df.monthday == today) & (df.type == "deaths")])
```

字段含义

- `monthday`：固定 `mm-dd` 格式，如 `12-31`
- `type`：枚举值 `events`/`births`/`deaths`
- `content`：纯文本，已去掉维基原文的 `[1]` 类脚注

更新周期

- 代码层：每周自动抓取一次（GitHub Actions）
- 数据层：维基百科内容如有更新，Release 版本号会递增，无需关注代码，直接下载最新数据集即可

授权与来源

原始文本版权归属 [维基百科](https://zh.wikipedia.org)（CC BY-SA 4.0）。

本仓库仅做结构化整理与导出，下游使用请遵守 CC BY-SA 4.0 协议——转载需署名“中文维基百科”。

反馈

发现数据缺失/乱码/格式错误，请开 [Issue](https://github.com/JaneDevStudio/History-in-Today-Data-set/issues) 并 @ 我们。