<?php
declare(strict_types = 1);

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\RouteCollectionBuilder;

class MicroKernel extends Kernel
{
    const AUTH_TOKEN = 'sampleAuthToken';
    use MicroKernelTrait;

    private $issues = [
        [
            "id"          => 123,
            "title"       => "Nie ma ciepłej wody na trzecim piętrze",
            "description" => "W męskiej łazience na trzecim piętrze nie ma ciepłej wody w pierszym kranie od lewej",
            "status"      => "open",
            "score"       => 0,
            "author"      => [
                "login"      => "jank",
                "first_name" => "Jan",
                "last_name"  => "Kowalski",
            ],
            "created_at"  => "2017-05-10T10:03:46+02:00",
        ],
        [
            "id"          => 234,
            "title"       => "Nie ma ciepłej wody na trzecim piętrze",
            "description" => "W męskiej łazience na trzecim piętrze nie ma ciepłej wody w pierszym kranie od lewej",
            "status"      => "open",
            "score"       => 0,
            "author"      => [
                "login"      => "jank",
                "first_name" => "Jan",
                "last_name"  => "Kowalski",
            ],
            "created_at"  => "2017-05-10T10:03:46+02:00",
        ]
    ];

    public function registerBundles()
    {
        return [new FrameworkBundle()];
    }

    protected function configureRoutes(RouteCollectionBuilder $routes)
    {
        $routes->add('/login', 'kernel:loginAction');
        $routes->add('/issues', 'kernel:issuesAction');
        $routes->add('/issues/123', 'kernel:issuesReadAction');
        $routes->add('/issues/123/comments', 'kernel:issuesCommentsAction');
        $routes->add('/issues/123/vote', 'kernel:issuesVoteAction');
        $routes->add('/token', 'kernel:tokenAction');
        $routes->add('/admin/generate_tokens', 'kernel:adminGenerateTokensAction');
        $routes->add('/admin/issues/123/status', 'kernel:adminIssuesStatusAction');
        $routes->add('/admin/ldapGroups', 'kernel:adminLdapGroupsAction');
    }

    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader)
    {
        $c->loadFromExtension('framework', ['secret' => 'secret']);
    }

    public function loginAction(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent());

        if ('tester' === $content->user && 'password123' === $content->password) {
            return new JsonResponse(['authenticationToken' => self::AUTH_TOKEN]);
        }

        return new JsonResponse(['error' => 'Wrong credentials'], Response::HTTP_UNAUTHORIZED);
    }

    public function issuesAction(Request $request): JsonResponse
    {
        if (!$this->isAuthorizedWithToken($request)) {
            return $this->invalidTokenResponse();
        }

        if (Request::METHOD_POST === $request->getMethod()) {
            return new JsonResponse($this->issues[0]);
        }

        return new JsonResponse($this->issues);
    }

    public function issuesReadAction(Request $request): JsonResponse
    {
        if (!$this->isAuthorizedWithToken($request)) {
            return $this->invalidTokenResponse();
        }

        $comments = [[
            "id" => 123,
            "text" => "Jestem morsem",
            "author" => [
                "login" => "jacekn",
                "first_name" => "Jacek",
                "last_name" => "Nowak"
            ],
            "created_at" => "2017-05-10T10:12:23+02:00"
        ]];

        $issueWithComments = array_merge($this->issues[0], ['comments' => $comments]);

        return new JsonResponse($issueWithComments);
    }

    public function issuesCommentsAction(Request $request): JsonResponse
    {
        if (self::AUTH_TOKEN !== $request->headers->get('Authorization')) {
            return $this->invalidTokenResponse();
        }

        return new JsonResponse(
            [
                "id" => 123,
                "text" => "Mi tam nie przeszkadza, jestem morsem",
                "created_at" => "2017-05-10T10:03:46+02:00"
            ],
            Response::HTTP_CREATED
        );
    }

    public function issuesVoteAction(Request $request): JsonResponse
    {
        if (!$this->isAuthorizedWithToken($request, 'sampleVotingToken')) {
            return $this->invalidTokenResponse('voting');
        }

        if ($this->isAuthorizedWithToken($request, 'notEnoughToken')) {
            return new JsonResponse(['error' => 'No votes left for the token'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isAuthorizedWithToken($request, 'sampleVotingToken')) {
            return $this->invalidTokenResponse('voting');
        }

        return new JsonResponse(['score' => 10]);
    }

    public function tokenAction(Request $request): JsonResponse
    {
        if (!$this->isAuthorizedWithToken($request, 'sampleVotingToken')) {
            return $this->invalidTokenResponse('voting');
        }

        return new JsonResponse(['points' => 5]);
    }

    public function adminGenerateTokensAction(Request $request): JsonResponse
    {
        if (!$this->isAuthorizedWithToken($request)) {
            return $this->invalidTokenResponse();
        }

        $content = json_decode($request->getContent());

        if (array_search('jan@1231231', $content->emails)) {
            return new JsonResponse(['error' => 'Invalid email: jan@1231231'], Response::HTTP_BAD_REQUEST);
        }

        if (array_search('szefowie', $content->ldapGroups)) {
            return new JsonResponse(['error' => 'ldapGroup \'szefowie\' doesn\'t exist'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse();
    }

    public function adminIssuesStatusAction(Request $request): JsonResponse
    {
        if (!$this->isAuthorizedWithToken($request)) {
            return $this->invalidTokenResponse();
        }

        $content = json_decode($request->getContent());

        if (!in_array($content->status, ['open', 'declined', 'resolved'])) {
            return new JsonResponse(['error' => 'Invalid status']);
         }

        return new JsonResponse();
    }

    public function adminLdapGroupsAction(Request $request): JsonResponse
    {
        if (!$this->isAuthorizedWithToken($request)) {
            return $this->invalidTokenResponse();
        }

        return new JsonResponse(['studenci', 'pracownicy']);
    }

    private function isAuthorizedWithToken(Request $request, string $requiredToken = self::AUTH_TOKEN): bool
    {
        return $requiredToken === $request->headers->get('Authorization');
    }

    private function invalidTokenResponse(string $tokenName = 'auth'): JsonResponse
    {
        return new JsonResponse(['error' => 'Invalid ' . $tokenName . ' token'], Response::HTTP_UNAUTHORIZED);
    }
}
