<?php
/**
 * Description of publisher
 *
 * @author Faizan Ayubi
 */
use Framework\RequestMethods as RequestMethods;
use Framework\Registry as Registry;

class Publisher extends Advertiser {

    /**
     * @readwrite
     */
    protected $_publish;

    /**
     * @before _secure, publisherLayout
     */
    public function index() {
        $this->seo(array("title" => "Dashboard", "description" => "Stats for your Data", "view" => $this->getLayoutView()));
        $view = $this->getActionView();
        
        $database = Registry::get("database");
        $paid = $database->query()->from("transactions", array("SUM(amount)" => "earn"))->where("user_id=?", $this->user->id)->where("live=?", 1)->all();
        $earn = $database->query()->from("transactions", array("SUM(amount)" => "earn"))->where("user_id=?", $this->user->id)->where("live=?", 0)->all();
        $ticket = Ticket::first(array("user_id = ?" => $this->user->id, "live = ?" => 1), array("subject", "id"), "created", "desc");
        $links = Link::all(array("user_id = ?" => $this->user->id, "live = ?" => true), array("id", "item_id", "short"), "created", "desc", 6, 1);
        
        $total = $database->query()->from("stats", array("SUM(amount)" => "earn", "SUM(click)" => "click"))->where("user_id=?", $this->user->id)->all();
    
        $view->set("total", $total);
        $view->set("paid", abs(round($paid[0]["earn"], 2)));
        $view->set("earn", round($earn[0]["earn"], 2));
        $view->set("links", $links);
        $view->set("ticket", $ticket)
            ->set("payout", Payout::first(array("user_id = ?" => $this->user->id, "live = ?" => 0)))
            ->set("fb", RequestMethods::get("fb", false));
    }
    
    /**
     * Shortens the url for publishers
     * @before _secure, publisherLayout
     */
    public function shortenURL() {
        $this->JSONview();
        $view = $this->getActionView();
        $link = new Link(array(
            "user_id" => $this->user->id,
            "short" => "",
            "item_id" => RequestMethods::get("item"),
            "live" => 1
        ));
        $link->save();
        
        $item = Item::first(array("id = ?" => RequestMethods::get("item")), array("url", "title", "image", "description"));
        $m = Registry::get("MongoDB")->urls;
        $doc = array(
            "link_id" => $link->id,
            "item_id" => RequestMethods::get("item"),
            "user_id" => $this->user->id,
            "url" => $item->url,
            "title" => $item->title,
            "image" => $item->image,
            "description" => $item->description,
            "created" => date('Y-m-d', strtotime("now"))
        );
        $m->insert($doc);

        $d = Meta::first(array("user_id = ?" => $this->user->id, "property = ?" => "domain"), array("value"));
        if($d) {
            $longURL = $d->value . '/' . base64_encode($link->id);
        } else {
            $domains = $this->target();
            $k = array_rand($domains);
            $longURL = RequestMethods::get("domain", $domains[$k]) . '/' . base64_encode($link->id);
        }

        //$link->short = $this->_bitly($longURL);
        $link->short = $longURL;
        $link->save();

        $view->set("shortURL", $link->short);
        $view->set("link", $link);
    }
    
    /**
     * @before _secure, publisherLayout
     */
    public function topearners() {
        $this->seo(array("title" => "Top Earners", "view" => $this->getLayoutView()));
        $view = $this->getActionView();$today = strftime("%Y-%m-%d", strtotime('now'));
        
        $collection = Registry::get("MongoDB")->clicks;
        $stats = array();$stat = array();
        $cursor = $collection->find(array('created' => $today));
        if ($cursor) {
            foreach ($cursor as $key => $record) {
                if (array_key_exists($record['user_id'], $stats)) {
                    $stats[$record['user_id']] += $record['click'];
                } else {
                    $stats[$record['user_id']] = $record['click'];
                }
            }

            $stats = $this->array_sort($stats, 'click', SORT_DESC);
            $count = 0;
            foreach ($stats as $key => $value) {
                array_push($stat, array(
                    "user_id" => $key,
                    "count" => $value
                ));
                if ($count > 8) {
                    break;
                }
                $count++;
            }
            $view->set("today", $stat);
        }

        $allnews = Meta::all(array("property = ?" => "news", "live = ?" => true), array("*"), "created", "desc", 5, 1);
        $view->set("allnews", $allnews);
    }
    
    /**
     * @before _secure, publisherLayout
     */
    public function profile() {
        $this->seo(array("title" => "Profile", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        switch (RequestMethods::post("action")) {
            case 'saveUser':
                $user = User::first(array("id = ?" => $this->user->id));
                $user->phone = RequestMethods::post("phone");
                $user->name = RequestMethods::post("name");
                $user->username = RequestMethods::post("username");
                $user->currency = RequestMethods::post("currency");
                if ($user->validate()) {
                    $view->set("message", "Saved <strong>Successfully!</strong>");
                    $user->save();
                } else {
                    $view->set("message", "Error see required fields");
                    $view->set("errors", $user->getErrors());
                }
                $view->set("user", $user);
                break;
            case "changePass":
                $user = User::first(array("id = ?" => $this->user->id));
                if ($user->password == sha1(RequestMethods::post("password"))) {
                    $user->password = sha1(RequestMethods::post("npassword"));
                    
                    $user->save();
                    $view->set("message", "Password Changed <strong>Successfully!</strong>");
                } else {
                    $view->set("message", "Incorrect old password entered");
                }
                break;
        }

        switch (RequestMethods::post("action")) {
            case 'addPaypal':
                $paypal = new Paypal(array(
                    "user_id" => $this->user->id,
                    "email" => RequestMethods::post("email")
                ));
                $paypal->save();
                $view->set("message", "Paypal Account Saved <strong>Successfully!</strong>");
                break;
            case 'addPaytm':
                $paytm = new Paytm(array(
                    "user_id" => $this->user->id,
                    "phone" => RequestMethods::post("number")
                ));
                $paytm->save();
                $view->set("message", "Paytm Account Saved <strong>Successfully!</strong>");
                break;
            case 'addBank':
                $bank = new Bank(array(
                    "user_id" => $this->user->id,
                    "name" => RequestMethods::post("name"),
                    "bank" => RequestMethods::post("bank"),
                    "number" => RequestMethods::post("number"),
                    "ifsc" => RequestMethods::post("ifsc"),
                    "pan" => RequestMethods::post("pan")
                ));
                $bank->save();
                $view->set("message", "Bank Account Saved <strong>Successfully!</strong>");
                break;
        }
        $banks = Bank::all(array("user_id = ?" => $this->user->id));
        $paypals = Paypal::all(array("user_id = ?" => $this->user->id), array("email"));
        $paytms = Paytm::all(array("user_id = ?" => $this->user->id), array("phone"));
        
        $view->set("banks", $banks);
        $view->set("paypals", $paypals);
        $view->set("paytms", $paytms);
    }
    
    /**
     * @before _secure, publisherLayout
     */
    public function payments() {
        $this->seo(array("title" => "Payments", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $page = RequestMethods::get("page", 1);
        $limit = RequestMethods::get("limit", 10);
        $transactions = Transaction::all(array("user_id = ?" => $this->user->id), array("id", "ref", "amount", "live", "created"), "created", "desc", $limit, $page);
        $count = Transaction::count(array("user_id = ?" => $this->user->id));
        
        $view->set("transactions", $transactions);
        $view->set("limit", $limit);
        $view->set("page", $page);
        $view->set("count", $count);
    }

    /**
     * @before _secure, publisherLayout
     */
    public function platforms() {
        $this->seo(array("title" => "Platforms", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $fbpages = FBPage::all(array("user_id = ?" => $this->user->id));
        $view->set("fbpages", $fbpages);
        $fb = RequestMethods::get("fb", false);
        $view->set("fb", $fb);
    }

    /**
     * @before _secure, publisherLayout
     */
    public function links() {
        $this->seo(array("title" => "Stats Charts", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $page = RequestMethods::get("page", 1);
        $limit = RequestMethods::get("limit", 10);
        $short = RequestMethods::get("short", "");
        $where = array(
            "short LIKE ?" => "%{$short}%",
            "user_id = ?" => $this->user->id,
            "live = ?" => true
        );

        $links = Link::all($where, array("id", "item_id", "short", "created"), "created", "desc", $limit, $page);
        $count = Link::count($where);

        $view->set("links", $links);
        $view->set("limit", $limit);
        $view->set("page", $page);
        $view->set("count", $count)
            ->set("fb", RequestMethods::get("fb"));
    }

    protected function target() {
        $session = Registry::get("session");
        $domains = $session->get("domains");

        $alias = array();
        foreach ($domains as $domain) {
            array_push($alias, $domain->value);
        }
        
        return $alias;
    }
    
    /**
     * @protected
     */
    public function publisherLayout() {
        $session = Registry::get("session");
        
        $publish = $session->get("publish");
        if (isset($publish)) {
            $this->_publish = $publish;
        } else {
            $user = $this->getUser();
            $publish = Publish::first(array("user_id = ?" => $user->id));
            if ($user && $publish) {
                $session->set("publish", $publish);
            } else {
                $this->redirect("/index.html");
            }
        }

        $this->defaultLayout = "layouts/publisher";
        $this->setLayout();
    }

    /**
     * @protected
     */
    public function render() {
        if ($this->publish) {
            if ($this->actionView) {
                $this->actionView->set("publish", $this->publish);
            }

            if ($this->layoutView) {
                $this->layoutView->set("publish", $this->publish);
            }
        }    
        parent::render();
    }

    /**
     * @before _session
     */
    public function register() {
        $this->seo(array("title" => "Register as Publisher", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        if (RequestMethods::post("action") == "register") {
            $exist = User::first(array("email = ?" => RequestMethods::post("email")));
            if ($exist) {
                $view->set("message", 'User exists, <a href="/auth/login.html">login</a>');
            } else {
                $errors = $this->_publisherRegister();
                $view->set("errors", $errors);
                if (empty($errors)) {
                    $view->set("message", "Your account has been created, Check your email for password");
                }
            }
        }
    }
}