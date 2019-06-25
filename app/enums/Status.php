<?php

namespace App\Enums;

class Status
{
    /**
     * 消息
     * 临时响应
     */
    //const Continue = 100;                     // 客户端应当继续发送请求
    //const SwitchingProtocols = 101;           // 服务器已经理解了客户端的请求，并将通过Upgrade 消息头通知客户端采用不同的协议来完成这个请求
    //const Processing = 102;                   // 处理将被继续执行
    /**
     * 成功
     * 请求已成功被服务器接收、理解、并接受
     */
    const OK = 200;                           // 请求已成功
    const Created = 201;                      // 请求已经被实现
    //const Accepted = 202;                     // 服务器已接受请求，但尚未处理
    //const NonAuthoritativeInfo = 203;         // 服务器已成功处理了请求,但返回的实体头部元信息不是在原始服务器上有效的确定集合，而是来自本地或者第三方的拷贝
    //const NoContent = 204;                    // 服务器成功处理了请求，但不需要返回任何实体内容
    //const ResetContent = 205;                 // 服务器成功处理了请求，且没有返回任何内容
    //const PartialContent = 206;               // 服务器已经成功处理了部分 GET 请求
    //const MultiStatus = 207;                  // 代表之后的消息体将是一个XML消息
    //const AlreadyReported = 208;              // RFC 5842, 7.1
    //const IMUsed = 226;                       // RFC 3229, 10.4.1
    /**
     * 重定向
     * 需要客户端采取进一步的操作才能完成请求
     */
    //const MultipleChoices = 300;              // 用户或浏览器能够自行选择一个首选的地址进行重定向
    //const MovedPermanently = 301;             // 被请求的资源已永久移动到新位置，并且将来任何对此资源的引用都应该使用本响应返回的若干个 URI 之一
    //const Found = 302;                        // 请求的资源临时从不同的 URI响应请求
    //const SeeOther = 303;                     // 对应当前请求的响应可以在另一个 URI 上被找到，而且客户端应当采用 GET 的方式访问那个资源
    //const NotModified = 304;                  // 禁止包含消息体，因此始终以消息头后的第一个空行结尾
    //const UseProxy = 305;                     // 被请求的资源必须通过指定的代理才能被访问。
    //const TemporaryRedirect = 307;            // 请求的资源临时从不同的URI 响应请求
    //const PermanentRedirect = 308;            // RFC 7538, 3
    /**
     * 请求错误
     * 客户端看起来可能发生了错误，妨碍了服务器的处理
     */
    const BadRequest = 400;                   // 请求参数有误
    const Unauthorized = 401;                 // 当前请求需要用户验证
    //const PaymentRequired = 402;              // 该状态码是为了将来可能的需求而预留的
    const Forbidden = 403;                    // 服务器已经理解请求，但是拒绝执行它
    const NotFound = 404;                     // 请求失败，请求所希望得到的资源未被在服务器上发现
    const MethodNotAllowed = 405;             // 请求行中指定的请求方法不能被用于请求相应的资源
    //const NotAcceptable = 406;                // 请求的资源的内容特性无法满足请求头中的条件，因而无法生成响应实体
    //const ProxyAuthRequired = 407;            // 与401响应类似，只不过客户端必须在代理服务器上进行身份验证
    const RequestTimeout = 408;               // 请求超时
    //const Conflict = 409;                     // 由于和被请求的资源的当前状态之间存在冲突，请求无法完成
    //const Gone = 410;                         // 被请求的资源在服务器上已经不再可用，而且没有任何已知的转发地址
    //const LengthRequired = 411;               // 服务器拒绝在没有定义 Content-Length 头的情况下接受请求
    //const PreconditionFailed = 412;           // 服务器在验证在请求的头字段中给出先决条件时，没能满足其中的一个或多个
    //const RequestEntityTooLarge = 413;        // 服务器拒绝处理当前请求，因为该请求提交的实体数据大小超过了服务器愿意或者能够处理的范围
    //const RequestURITooLong = 414;            // 请求的URI 长度超过了服务器能够解释的长度，因此服务器拒绝对该请求提供服务
    //const UnsupportedMediaType = 415;         // 对于当前请求的方法和所请求的资源，请求中提交的实体并不是服务器中所支持的格式，因此请求被拒绝
    //const RequestedRangeNotSatisfiable = 416; // 如果请求中包含了 Range 请求头，并且 Range 中指定的任何数据范围都与当前资源的可用范围不重合，同时请求中又没有定义 If-Range 请求头，那么服务器就应当返回416状态码
    //const ExpectationFailed = 417;            // 从当前客户端所在的IP地址到服务器的连接数超过了服务器许可的最大范围
    //const Teapot = 418;                       // RFC 7168, 2.3.3
    //const UnprocessableEntity = 422;          // 请求格式正确，但是由于含有语义错误，无法响应
    //const Locked = 423;                       // 当前资源被锁定
    //const FailedDependency = 424;             // 由于之前的某个请求发生的错误，导致当前请求失败，例如 PROPPATCH
    /**
     * 需要app升级到最新版本，强制升级(慎用)
     */
    const GONE = 410;                           //app请求的资源在服务器上已经不再可
    const UpgradeRequired = 426;
    //const PreconditionRequired = 428;         // RFC 6585, 3
    //const TooManyRequests = 429;              // 由微软扩展，代表请求应当在执行完适当的操作后进行重试
    //const RequestHeaderFieldsTooLarge = 431;  // RFC 6585, 5
    //const UnavailableForLegalReasons = 451;   // 该请求因法律原因不可用
    /**
     * 服务器错误
     */
    const InternalServerError = 500;          // 服务器遇到了一个未曾预料的状况，导致了它无法完成对请求的处理
    //const NotImplemented = 501;               // 服务器不支持当前请求所需要的某个功能
    //const BadGateway = 502;                   // 作为网关或者代理工作的服务器尝试执行请求时，从上游服务器接收到无效的响应
    //const ServiceUnavailable = 503;           // 由于临时的服务器维护或者过载，服务器当前无法处理请求。这个状况是临时的，并且将在一段时间以后恢复
    //const GatewayTimeout = 504;               // 作为网关或者代理工作的服务器尝试执行请求时，未能及时从上游服务器（URI标识出的服务器，例如HTTP、FTP、LDAP）或者辅助服务器（例如DNS）收到响应
    //const HTTPVersionNotSupported = 505;      // 服务器不支持，或者拒绝支持在请求中使用的 HTTP 版本
    //const VariantAlsoNegotiates = 506;        // 由《透明内容协商协议》（RFC 2295）扩展，代表服务器存在内部配置错误：被请求的协商变元资源被配置为在透明内容协商中使用自己，因此在一个协商处理中不是一个合适的重点
    //const InsufficientStorage = 507;          // 服务器无法存储完成请求所必须的内容。这个状况被认为是临时的
    //const LoopDetected = 508;                 // RFC 5842, 7.2
    //const BandwidthLimitExceeded = 509;       // 服务器达到带宽限制
    //const NotExtended = 510;                  // 获取资源所需要的策略并没有没满足
    //const NetworkAuthenticationRequired = 511;// RFC 6585, 6
    //const UnparseableResponseHeaders = 600;   // 源站没有返回响应头部，只返回实体内容
}
