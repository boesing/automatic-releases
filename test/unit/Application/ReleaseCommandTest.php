<?php

declare(strict_types=1);

namespace Doctrine\AutomaticReleases\Test\Unit\Application;

use Doctrine\AutomaticReleases\Application\Command\ReleaseCommand;
use Doctrine\AutomaticReleases\Environment\Variables;
use Doctrine\AutomaticReleases\Git\CreateTag;
use Doctrine\AutomaticReleases\Git\Fetch;
use Doctrine\AutomaticReleases\Git\GetMergeTargetCandidateBranches;
use Doctrine\AutomaticReleases\Git\Push;
use Doctrine\AutomaticReleases\Git\Value\BranchName;
use Doctrine\AutomaticReleases\Git\Value\MergeTargetCandidateBranches;
use Doctrine\AutomaticReleases\Git\Value\SemVerVersion;
use Doctrine\AutomaticReleases\Github\Api\GraphQL\Query\GetGithubMilestone;
use Doctrine\AutomaticReleases\Github\Api\GraphQL\Query\GetMilestoneChangelog\Response\Milestone;
use Doctrine\AutomaticReleases\Github\Api\V3\CreateRelease;
use Doctrine\AutomaticReleases\Github\CreateReleaseText;
use Doctrine\AutomaticReleases\Github\Event\Factory\LoadCurrentGithubEvent;
use Doctrine\AutomaticReleases\Github\Event\MilestoneClosedEvent;
use Doctrine\AutomaticReleases\Github\Value\RepositoryName;
use Doctrine\AutomaticReleases\Gpg\SecretKeyId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use function sys_get_temp_dir;
use function tempnam;

final class ReleaseCommandTest extends TestCase
{
    /** @var Variables&MockObject */
    private Variables $variables;
    /** @var LoadCurrentGithubEvent&MockObject */
    private LoadCurrentGithubEvent $loadEvent;
    /** @var Fetch&MockObject */
    private Fetch $fetch;
    /** @var GetMergeTargetCandidateBranches&MockObject */
    private GetMergeTargetCandidateBranches $getMergeTargets;
    /** @var GetGithubMilestone&MockObject */
    private GetGithubMilestone $getMilestone;
    /** @var CreateReleaseText&MockObject */
    private CreateReleaseText $createReleaseText;
    /** @var CreateTag&MockObject */
    private CreateTag $createTag;
    /** @var Push&MockObject */
    private Push $push;
    /** @var CreateRelease&MockObject */
    private CreateRelease $createRelease;
    private ReleaseCommand $command;
    private MilestoneClosedEvent $event;
    private MergeTargetCandidateBranches $branches;
    private Milestone $milestone;
    private SemVerVersion $releaseVersion;
    private SecretKeyId $signingKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->variables         = $this->createMock(Variables::class);
        $this->loadEvent         = $this->createMock(LoadCurrentGithubEvent::class);
        $this->fetch             = $this->createMock(Fetch::class);
        $this->getMergeTargets   = $this->createMock(GetMergeTargetCandidateBranches::class);
        $this->getMilestone      = $this->createMock(GetGithubMilestone::class);
        $this->createReleaseText = $this->createMock(CreateReleaseText::class);
        $this->createTag         = $this->createMock(CreateTag::class);
        $this->push              = $this->createMock(Push::class);
        $this->createRelease     = $this->createMock(CreateRelease::class);

        $this->command = new ReleaseCommand(
            $this->variables,
            $this->loadEvent,
            $this->fetch,
            $this->getMergeTargets,
            $this->getMilestone,
            $this->createReleaseText,
            $this->createTag,
            $this->push,
            $this->createRelease
        );

        $this->event = MilestoneClosedEvent::fromEventJson(<<<'JSON'
{
    "milestone": {
        "title": "1.2.3",
        "number": 123
    },
    "repository": {
        "full_name": "foo/bar"
    },
    "action": "closed"
}
JSON
        );

        $this->branches = MergeTargetCandidateBranches::fromAllBranches(
            BranchName::fromName('1.1.x'),
            BranchName::fromName('1.2.x'),
            BranchName::fromName('1.3.x'),
            BranchName::fromName('master'),
        );

        $this->milestone = Milestone::fromPayload([
            'number'       => 123,
            'closed'       => true,
            'title'        => 'The title',
            'description'  => 'The description',
            'issues'       => [
                'nodes' => [],
            ],
            'pullRequests' => [
                'nodes' => [],
            ],
            'url'          => 'http://example.com/milestone',
        ]);

        $this->releaseVersion = SemVerVersion::fromMilestoneName('1.2.3');
        $this->signingKey = SecretKeyId::fromBase16String('aabbccddeeff');

        $this->variables->method('signingSecretKey')
            ->willReturn($this->signingKey);
        $this->variables->method('githubToken')
            ->willReturn('github-auth-token');
    }

    public function testCommandName(): void
    {
        self::assertSame('doctrine:automatic-releases:release', $this->command->getName());
    }

    public function testWillRelease(): void
    {
        $workspace = tempnam(sys_get_temp_dir(), 'workspace');

        unlink($workspace);
        mkdir($workspace);
        mkdir($workspace . '/.git');

        $this->variables->method('githubWorkspacePath')
            ->willReturn($workspace);

        $this->loadEvent->method('__invoke')
            ->willReturn($this->event);

        $this->fetch->expects(self::once())
            ->method('__invoke')
            ->with('https://github-auth-token:x-oauth-basic@github.com/foo/bar.git', $workspace);

        $this->getMergeTargets->method('__invoke')
            ->with($workspace)
            ->willReturn($this->branches);

        $this->getMilestone->method('__invoke')
            ->with(self::equalTo(RepositoryName::fromFullName('foo/bar')), 123)
            ->willReturn($this->milestone);

        $this->createReleaseText->method('__invoke')
            ->with(
                self::equalTo($this->milestone),
                self::equalTo(RepositoryName::fromFullName('foo/bar')),
                self::equalTo($this->releaseVersion)
            )
            ->willReturn('text of the changelog');

        $this->createTag->expects(self::once())
            ->method('__invoke')
            ->with(
                $workspace,
                self::equalTo(BranchName::fromName('1.2.x')),
                '1.2.3',
                'text of the changelog',
                self::equalTo($this->signingKey)
            );

        $this->push->expects(self::once())
            ->method('__invoke')
            ->with($workspace, '1.2.3');

        $this->createRelease->expects(self::once())
            ->method('__invoke')
            ->with(
                self::equalTo(RepositoryName::fromFullName('foo/bar')),
                self::equalTo($this->releaseVersion),
                'text of the changelog'
            );

        $this->command->run(new ArrayInput([]), new NullOutput());
    }
}
