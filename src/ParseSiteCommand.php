<?php

/*
 * This file is part of the QBBR code.
 *
 * (c) Sokolov Innokenty <imqbbr@gmail.com>
 */

namespace QBBR;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

class ParseSiteCommand extends Command
{
    const URI_ALL_CARS_LIST = 'https://auto.ru/cars/all/';
    const PAGINATION_PARAMS = '?sort=fresh_relevance_1-desc&page=';
    const MAX_GET_HTML_ATTEMPTS = 5;
    const MIN_NORMAL_HTML_LENGHT = 100000;
    const MAX_PAGES_TO_PARSE = 5;

    private $currentPage = 1;
    private $attempts = 0;
    private $headerCols = ['Наименование', 'Цена', 'Год', 'Пробег', 'ID', 'Ссылка'];

    /**
     * @var OutputInterface
     */
    private $output;

    protected static $defaultName = 'start';

    protected function configure()
    {
        $this->setDescription('Парсит сайт auto.ru');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $crawler = $this->getPageCrawler(1);
        $itemsPerPage = $this->getItemsPerPage($crawler);
        $pagesCount = $this->getPagesCount($crawler);

        $output->writeln(sprintf('Эл-ов на странице: %d.', $itemsPerPage));
        $output->writeln(sprintf('Всего страниц: %d.', $pagesCount));
        $output->writeln(sprintf('Всего эл-ов: ~%d.', $itemsPerPage * $pagesCount));

        $progressBar = new ProgressBar($output, self::MAX_PAGES_TO_PARSE < $pagesCount ? self::MAX_PAGES_TO_PARSE : $pagesCount);
        $progressBar->start();

        $data = $this->getData($crawler, 1);
        $progressBar->advance();
        $output->writeln('');

        for ($i = 1; $i <= $pagesCount; ++$i) {
            $crawler = $this->getPageCrawler($i);
            $data = array_merge($data, $this->getData($crawler, $i));
            $progressBar->advance();
            $output->writeln('');

            if (self::MAX_PAGES_TO_PARSE === $i) {
                break;
            }
        }

        $progressBar->finish();

        //dump($data);

        $filename = sprintf('autoru_dump__%s.xlsx', (new \DateTime())->format('mdY_His'));
        $output->write(sprintf('Сохраняем в %s ... ', $filename));
        $this->saveToXlsx($data, Utils::getBaseDir().'/data/'.$filename);
        $output->writeln('<info>OK</info>');
    }

    private function getPageCrawler(int $page = 1): Crawler
    {
        $url = self::URI_ALL_CARS_LIST;

        if ($page > 1) {
            $url .= self::PAGINATION_PARAMS.$page;
        }

        $html = $this->getHtml($url);
        $crawler = new Crawler($html);

        return $crawler;
    }

    private function getHtml($url)
    {
        $proxy = new TorProxy();
        //$proxy->setUserAgent((\Faker\Factory::create())->chrome);
        //$proxy->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/74.0.3729.169 Safari/537.36');
        $proxy->setUserAgent('googlebot');
        $html = $proxy->curl($url);

        if (mb_strlen($html) < self::MIN_NORMAL_HTML_LENGHT) {
            ++$this->attempts;
            $this->output->writeln('<comment>Полученный контент слишком маленький, это каптча или блок запроса. Получаем новый идентификатор прокси...</comment>');
            $this->output->writeln(sprintf('Попытка: %d/%d.', $this->attempts, self::MAX_GET_HTML_ATTEMPTS));
            $proxy->changeTorCircuits();
            usleep(3000);

            $this->output->writeln(sprintf('<info>OK</info>'));

            if (self::MAX_GET_HTML_ATTEMPTS === $this->attempts) {
                $this->output->writeln('<error>Попытки закончились. Попробуйте перезапустить приложение.</error>');
                exit;
            }

            return $this->getHtml($url);
        }

        $this->attempts = 0;

        return $html;
    }

    private function getItemsPerPage(Crawler $crawler): int
    {
        return $crawler->filter('body .ListingItem-module__main')->count();
    }

    private function getPagesCount(Crawler $crawler): int
    {
        return (int) $crawler->filter('.ListingPagination-module__pages .Button:last-child .Button__text')->text();
    }

    private function getData(Crawler $crawler, int $page): array
    {
        $this->output->writeln(sprintf('Парсим страницу: %d.', $page));

        return $crawler->filter('body .ListingItem-module__main')->each(function (Crawler $node, $i) {
            $linkNode = $node->filter('.ListingItemTitle-module__link');

            $data[] = $linkNode->text();
            $data[] = $node->filter('.ListingItemPrice-module__content')->count() > 0 ? $node->filter('.ListingItemPrice-module__content')->text() : '';
            $data[] = $node->filter('.ListingItem-module__year')->count() > 0 ? $node->filter('.ListingItem-module__year')->text() : '';
            $data[] = $node->filter('.ListingItem-module__kmAge')->count() > 0 ? $node->filter('.ListingItem-module__kmAge')->text() : '';
            $href = $linkNode->attr('href');
            $data[] = 1 === preg_match('#/(\w+-\w+)/#', $href, $matches) ? $matches[1] : '';
            $data[] = $href;

            return $data;
        });
    }

    private function saveToXlsx(array $data, string $filename): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // write headers
        foreach ($this->headerCols as $i => $colName) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $colName);
        }

        // write data
        $rowN = 2;
        foreach ($data as $row) {
            $colN = 1;

            foreach ($row as $value) {
                $sheet->setCellValueByColumnAndRow($colN, $rowN, $value);
                ++$colN;
            }

            ++$rowN;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($filename);
    }
}
