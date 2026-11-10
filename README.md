# ДЗ 13. Анализ YouTube-каналов в Elasticsearch

Два индекса хранят каналы и видео. Репозитории отвечают за добавление, массовую запись, чтение и удаление. `ChannelStats` считает суммарные реакции канала и строит топ N по отношению лайков к дизлайкам средствами агрегаций Elasticsearch. Опциональный паук использует YouTube Data API.

## Запуск

```bash
docker compose up -d elasticsearch
docker compose --profile tools run --rm app php bin/init.php
docker compose --profile tools run --rm app php bin/seed.php
```

Массовая загрузка четырёх тысяч тестовых документов:

```bash
docker compose --profile tools run --rm app php bin/bulk.php 4000 40
```

Для паука создайте локальный `.env` с `YOUTUBE_API_KEY` и выполните:

```bash
docker compose --profile tools run --rm app php bin/spider.php search "php" 5 20
```

Ключ API в репозиторий не добавляется.
