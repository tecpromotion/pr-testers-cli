<?php

require __DIR__ . '/../vendor/autoload.php';

use Joomla\Http\HttpFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Dotenv\Dotenv;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');


class GithubCommentsCli extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('github-pr-comments')
            ->setDescription('Fetch merged PR comments by milestone + filter by phrase')
            ->addOption('token', null, InputOption::VALUE_OPTIONAL, 'GitHub token')
            ->addOption('owner', null, InputOption::VALUE_OPTIONAL, 'GitHub owner')
            ->addOption('repo', null, InputOption::VALUE_OPTIONAL, 'GitHub repository')
            ->addOption('base', null, InputOption::VALUE_OPTIONAL, 'PR base branch')
            ->addOption('milestone', null, InputOption::VALUE_OPTIONAL, 'PR milestone')
            ->addOption('keyword', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter keyword(s)')
            ->addOption('merged-since', null, InputOption::VALUE_OPTIONAL, 'Date filter for merged PRs (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token       = $input->getOption('token') ?? $_ENV['GITHUB_TOKEN'] ?? null;
        $owner       = $input->getOption('owner') ?? $_ENV['GITHUB_OWNER'] ?? null;
        $repo        = $input->getOption('repo') ?? $_ENV['GITHUB_REPO'] ?? null;
        $base        = $input->getOption('base') ?? $_ENV['GITHUB_BASE'] ?? '6.0-dev';
        $milestone   = $input->getOption('milestone') ?? $_ENV['GITHUB_MILESTONE'] ?? 'Joomla! 6.0.0';
        $mergedSince = $input->getOption('merged-since') ?? null;

        // Validate required parameters
        if (!$token) {
            $output->writeln('<error>GitHub token is required. Set GITHUB_TOKEN in .env or use --token option.</error>');
            return 1;
        }

        if (!$owner) {
            $output->writeln('<error>GitHub owner is required. Set GITHUB_OWNER in .env or use --owner option.</error>');
            return 1;
        }

        if (!$repo) {
            $output->writeln('<error>GitHub repository is required. Set GITHUB_REPO in .env or use --repo option.</error>');
            return 1;
        }

        // Validate date filter if present
        if ($mergedSince && (!($date = \DateTime::createFromFormat('Y-m-d', $mergedSince)) || $date->format('Y-m-d') !== $mergedSince)) {
            $output->writeln('<error>Invalid date format for --merged-since. Please use YYYY-MM-DD.</error>');
            return 1;
        }

        // Build keywords list: use CLI options if provided, otherwise env
        $cliKeywords = $input->getOption('keyword');
        if (\is_array($cliKeywords) && \count($cliKeywords) > 0) {
            $keywords = $cliKeywords;
        } else {
            $envList = $_ENV['GITHUB_KEYWORDS'] ?? null;
            if ($envList) {
                $keywords = array_map('trim', explode(',', $envList));
            } else {
                $keywords = [];
            }
        }

        // Initialize collection by author
        $collected = [];
        $client    = (new HttpFactory())->getHttp([
            'headers' => [
                'Authorization' => 'bearer ' . $token,
                'User-Agent'    => 'joomla-cli-gql',
                'Content-Type'  => 'application/json',
            ],
        ]);

        // Prepare GraphQL search query with milestone filter
        $query = <<<'GQL'
query($queryString:String!,$after:String){
  search(query:$queryString,type:ISSUE,last:100,after:$after){
    pageInfo{hasNextPage,endCursor}
    nodes{ ... on PullRequest {
        number
        title
        milestone{ title }
        comments(last:100){ nodes{ author{login} body createdAt } }
    }}
  }
}
GQL;

        $after = null;
        do {
            // Build search string including milestone
            $queryString = \sprintf('repo:%s/%s is:pr is:merged base:%s milestone:"%s"', $owner, $repo, $base, $milestone);
            // Add date filter if provided
            if ($mergedSince) {
                $queryString .= ' merged:>=' . $mergedSince;
            }
            $payload     = ['query' => $query, 'variables' => ['queryString' => $queryString, 'after' => $after]];
            $response    = $client->post('https://api.github.com/graphql', json_encode($payload));
            $data        = json_decode($response->getBody());

            if ($response->getStatusCode() !== 200) {
                $output->writeln('<error>Error fetching data: ' . $response->getReasonPhrase() . '</error>');
                return 1;
            }

            if (isset($data->errors)) {
                $output->writeln('<error>Error fetching data: ' . $data->errors[0]->message . '</error>');
                return 1;
            }

            $outputResults = ($mergedSince) ?
                \sprintf(
                    "Found %d PRs merged since %s in %s/%s with milestone '%s':",
                    \count($data->data->search->nodes),
                    $mergedSince,
                    $owner,
                    $repo,
                    $milestone
                ) : \sprintf(
                    "Found %d PRs in %s/%s with milestone '%s':",
                    \count($data->data->search->nodes),
                    $owner,
                    $repo,
                    $milestone
                );

            $output->writeln($outputResults);

            foreach ($data->data->search->nodes as $pr) {

                foreach ($pr->comments->nodes as $comment) {
                    // Only print if all defined keywords are present
                    $allFound = true;
                    foreach ($keywords as $kw) {
                        if (stripos($comment->body, $kw) === false) {
                            $allFound = false;
                            break;
                        }
                    }
                    if (! $allFound) {
                        continue;
                    }
                    // Collect comment under author login, keyed by PR number to avoid duplicates
                    $author = $comment->author?->login;

                    if ($comment->author === null) {
                        continue;
                    }

                    $prKey  = $pr->number;
                    if (! isset($collected[$author][$prKey])) {
                        $collected[$author][$prKey] = [
                            'pr'        => $prKey,
                            'title'     => $pr->title,
                            'comment'   => $comment->body,
                            'createdAt' => $comment->createdAt,
                        ];
                    }
                }
            }

            $pageInfo = $data->data->search->pageInfo;
            $hasNext  = $pageInfo->hasNextPage;
            $after    = $pageInfo->endCursor;
        } while ($hasNext);

        // Check if any test comments were found
        if (empty($collected)) {
            $output->writeln('<comment>No test comments found matching the criteria. No files written.</comment>');
            return 0;
        }

        // Sort authors
        uksort($collected, 'strcasecmp');

        // Write markdown output
        $header              = "## :technologist: Test contributions\n\n";
        $header .= "Thank you to all the testers who help us maintain high quality standards and deliver a robust product.\n\n";
        $contributorList     = [];
        $contributorListFull = [];

        // Output collected comments
        foreach ($collected as $author => $comments) {
            $countTests            = \count($comments);
            $contributorList[]     = "@{$author} ({$countTests})";
            $contributorListFull[] = "- @{$author} ({$countTests})\n";

            $output->writeln(\sprintf("Tests by %s:", $author));
            foreach ($comments as $comment) {
                $contributorListFull[] = "    - PR #{$comment['pr']}: {$comment['title']}\n";
                $output->writeln(\sprintf(" - PR #%d: %s", $comment['pr'], $comment['title']));
            }
        }

        // Build summary and full markdown
        $md     = $header . implode(', ', $contributorList) . "\n";
        $mdFull = $header . implode('', $contributorListFull);

        // Write files with error handling
        $summaryFile = __DIR__ . '/../collaborator-tester.md';
        $fullFile    = __DIR__ . '/../collaborator-tester-full.md';

        if (file_put_contents($summaryFile, $md) === false) {
            $output->writeln('<error>Failed to write summary file: ' . $summaryFile . '</error>');
            return 1;
        }

        if (file_put_contents($fullFile, $mdFull) === false) {
            $output->writeln('<error>Failed to write full file: ' . $fullFile . '</error>');
            return 1;
        }

        $output->writeln(\sprintf('<info>Successfully written to %s and %s</info>', $summaryFile, $fullFile));

        return 0;
    }
}

// Bootstrap Symfony Console application
$command = new GithubCommentsCli();
$app     = new Application('GitHub PR Comments', '1.0.0');
$app->addCommand($command);
$app->setDefaultCommand('github-pr-comments', true);
$app->run();
