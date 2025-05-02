# DDNS-Worker

> 利用CF Worker实现的自动更新 DNS记录（并内置TG消息渠道）


满足精神洁癖和Key洁癖需求的迷你DDNS脚本~


## Install

### Windows

- 主控端：请下载PHP端(单点版)运行 / `php ddns.php`
- 当前版本暂不支持节点端运行

### Linux

首先在CF平台部署主控端 ( `worker` )，即 `worker.js`

之后运行节点端即可(放哪里都行)

节点端安装: `wget --no-check-certificate -O ddns.sh https://raw.githubusercontent.com/chunkburst/ddns-worker/refs/heads/main/src/ddns.sh && chmod +x ddns.sh`

修改 `ddns.sh` 文件内的配置后执行： `./ddns.sh`

(执行后会自动加入定时任务)



## Process

> 它由哪些组成?

- Worker：可部署于CF Worker的js主控端

- PHP Worker: 同上，但是PHP版

- DDNS.sh: 节点端

> 它做了什么?

因为一般的DDNS工具会通过key来联系DNS供应商。

在部署过程中需要写入大量环境变量，使得步骤较为麻烦

如果Key发生更新等，意味着全都必须重新更新。

并且如果部署DDNS后，Key会遗留在程序中 (对于精神洁癖来说不太能接受，哪怕它一直是安全的)。

> 那它的作用是?

写好通用参数后，只需要修改一个域名前缀，下载到VPS中，运行即可~

> 具体运行过程?

- 部署DDNS脚本
- 脚本启动后开始尝试获取IP (根据类型选择IPv4或IPv6)
- 获取IP后开始更新DNS记录
- 写入定时任务，按照参数内时间间隔进行获取 (如果没有的话)



## Features

- [x] 极简
- [x] 极小化部署(能跑shell就行)
- [x] 无需动脑（无需下载，点击即玩）



## Uninstall

删除脚本本体 + crontab移除任务即可~



## Support

- 目前仅支持 `CloudFlare`

请CCB喝杯咖啡！【支持：ETH链 / 币安链(BSC) / Poly链等】

收款地址: `0x34ec2df7a44dfb252ed549a12b329eebfa016117`

![usdt](https://crimson-rear-ladybug-723.mypinata.cloud/ipfs/bafkreid363e4wtolwxsxtvgrswlftk2cb532x5dgrevawixgqrghomoqve)